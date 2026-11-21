using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Linq;
using System.Management;
using System.Text;
using System.Text.RegularExpressions;

namespace RepaPOS.Conector
{
    /// <summary>
    /// Junta lo que Windows sabe de cada teléfono conectado por USB:
    /// - Android (MTP): marca por el fabricante del USB, serie del USB y modelo del nombre que reporta; con ADB, el modelo exacto.
    /// - iPhone encendido: modelo, serie e iOS preguntando al servicio de Apple (usbmuxd / lockdownd).
    /// - iPhone en Recovery o DFU: serie (SRNM), ECID y modelo por el chip (CPID/BDID) del identificador USB.
    /// </summary>
    public static class Detector
    {
        static readonly Dictionary<string, string> Marcas = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
        {
            { "04E8", "Samsung" }, { "05AC", "Apple" }, { "18D1", "Google" }, { "2717", "Xiaomi" }, { "22B8", "Motorola" },
            { "12D1", "Huawei" }, { "2A70", "OnePlus" }, { "0BB4", "HTC" }, { "1004", "LG" }, { "0FCE", "Sony" },
            { "2D95", "Vivo" }, { "19D2", "ZTE" }, { "1BBB", "Alcatel / TCL" }, { "22D9", "Oppo" }, { "2A45", "Meizu" },
            { "17EF", "Lenovo" }, { "2E04", "Nokia" }, { "0E8D", "MediaTek (genérico)" }, { "1EBF", "Infinix / Tecno" },
            { "29A9", "Realme" }, { "05C6", "Qualcomm (genérico)" }, { "1782", "Spreadtrum (genérico)" },
        };

        // modos USB de Apple: normal (12xx) y recuperación
        static readonly Dictionary<string, string> ModosApple = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
        {
            { "1281", "Recovery" }, { "1280", "Recovery" }, { "1282", "Recovery" }, { "1283", "Recovery" }, { "1227", "DFU" }, { "1222", "DFU" },
        };

        public static List<Dictionary<string, object>> Detectar()
        {
            var resultado = new List<Dictionary<string, object>>();
            List<Pnp> dispositivos;
            try { dispositivos = Pnp.Listar(); }
            catch (Exception ex)
            {
                resultado.Add(new Dictionary<string, object> { { "error", "No se pudo leer los USB: " + ex.Message } });
                return resultado;
            }

            var iphonesNormales = new List<Pnp>();
            var vistos = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            Dictionary<string, AdbEquipo> adb = null;

            foreach (var d in dispositivos.Where(x => x.EsRaiz && Marcas.ContainsKey(x.Vid)))
            {
                // "&MODEM" solo cuenta como equipo si no está el dispositivo principal (Samsung en Download no lo tiene)
                if (d.Extra != "" && dispositivos.Any(x => x.Vid == d.Vid && x.Pid == d.Pid && x.EsRaiz && x.Extra == "")) continue;
                string llave = d.Vid + d.Pid + d.Serial;
                if (!vistos.Add(llave)) continue;

                if (d.Vid.Equals("05AC", StringComparison.OrdinalIgnoreCase))
                {
                    string modo;
                    if (ModosApple.TryGetValue(d.Pid, out modo)) resultado.Add(Recuperacion(d, modo));
                    else if (Restaurando(d, dispositivos)) resultado.Add(EnRestauracion(d));   // no se le habla: podría estorbar
                    else if (EsTelefonoApple(d, dispositivos)) iphonesNormales.Add(d);
                    continue;
                }

                // modos de flasheo (BROM/Preloader de MediaTek, EDL de Qualcomm, descarga de Spreadtrum): el teléfono no dice quién es
                var servicio = ModoServicio(d, dispositivos);
                if (servicio != null) { resultado.Add(servicio); continue; }

                // Android: el modelo que reporta la interfaz MTP (p. ej. "Galaxy A52s 5G"); con ADB, el de fábrica (SM-A528B)
                if (adb == null) adb = AdbEquipo.Listar();
                var t = new Dictionary<string, object>
                {
                    { "tipo", "android" },
                    { "marca", Marcas[d.Vid] },
                    { "modelo", NombreModelo(d, dispositivos) },
                    { "serial", d.Serial },
                    { "imei", null },
                    { "modo", ModoAndroid(d, dispositivos) },
                };
                if (d.Vid == "04E8") LeerModemSamsung(t, d, dispositivos);   // modelo de fábrica, serie e IMEI sin adb
                AdbEquipo a;
                if (d.Serial != "" && adb.TryGetValue(d.Serial, out a))
                {
                    if (a.Estado == "device")
                    {
                        if (a.Modelo != "") t["modelo_tecnico"] = a.Modelo;
                        if (a.Comercial != "") t["modelo"] = a.Comercial;
                        else if (a.Modelo != "" && (string)t["modelo"] == "") t["modelo"] = a.Modelo;
                        if (a.Marca != "") t["marca"] = a.Marca;
                        if (a.Android != "") t["sistema"] = "Android " + a.Android;
                        if (a.Imei != "" && t["imei"] == null) t["imei"] = a.Imei;
                        if (!((string)t["modo"]).Contains("ADB")) t["modo"] = t["modo"] + " + ADB";
                    }
                    else if (a.Estado == "unauthorized")
                        t["aviso"] = "Acepta \"Permitir depuración USB\" en el teléfono para leer el modelo exacto.";
                }
                if (d.Serial == "") t["aviso"] = "El teléfono no reportó número de serie por USB. Desbloquéalo y elige \"Transferir archivos\".";
                // sin módem (Samsung) ni adb que lo dé, el IMEI no se puede leer por USB: se marca *#06#
                if (t["imei"] == null && !t.ContainsKey("aviso"))
                    t["aviso"] = ((string)t["modo"]).Contains("ADB")
                        ? "Este Android no deja leer el IMEI por USB (Android 10 o más nuevo): márcalo con *#06# y escríbelo."
                        : "El IMEI de esta marca no se puede leer por MTP: márcalo con *#06# y escríbelo (o activa la depuración USB para intentarlo).";
                resultado.Add(t);
            }

            if (iphonesNormales.Count > 0) resultado.AddRange(Iphones(iphonesNormales));
            return resultado;
        }

        // ------------------------------------------------------------------ iPhone

        /// <summary>Un programa lo está restaurando o actualizando (las interfaces USB dicen RESTORE_MODE).</summary>
        static bool Restaurando(Pnp d, List<Pnp> todos)
        {
            return todos.Any(x => x.Vid == d.Vid && x.Pid == d.Pid && x.Id.IndexOf("RESTORE_MODE", StringComparison.OrdinalIgnoreCase) >= 0);
        }

        static Dictionary<string, object> EnRestauracion(Pnp d)
        {
            // el identificador es el UDID: 8 dígitos del chip (CPID), guion, ECID
            var m = Regex.Match(d.Serial, @"^0000([0-9A-F]{4})-([0-9A-F]+)$", RegexOptions.IgnoreCase);
            var t = new Dictionary<string, object>
            {
                { "tipo", "iphone" }, { "marca", "Apple" }, { "modelo", "iPhone" }, { "serial", null }, { "imei", null },
                { "udid", d.Serial }, { "modo", "Restaurando" },
                { "aviso", "Se está restaurando o actualizando. Espera a que termine para leer sus datos." },
            };
            if (m.Success)
            {
                t["cpid"] = m.Groups[1].Value.ToUpperInvariant();
                t["ecid"] = m.Groups[2].Value.ToUpperInvariant();
                t["modelo"] = Tablas.NombrePorChip(m.Groups[1].Value.ToUpperInvariant(), "");
            }
            return t;
        }

        static bool EsTelefonoApple(Pnp d, List<Pnp> todos)
        {
            // iPhone, iPad y iPod usan PID 12xx; los cargadores, teclados, etc. no
            return d.Pid.StartsWith("12", StringComparison.OrdinalIgnoreCase)
                || todos.Any(x => x.Vid == d.Vid && x.Pid == d.Pid && x.Nombre.IndexOf("Apple Mobile Device", StringComparison.OrdinalIgnoreCase) >= 0);
        }

        static IEnumerable<Dictionary<string, object>> Iphones(List<Pnp> usb)
        {
            List<Dictionary<string, object>> lista;
            string fallo = null;
            try { lista = Usbmux.Dispositivos(); }
            catch (Exception ex) { lista = new List<Dictionary<string, object>>(); fallo = ex.Message; }

            foreach (var d in usb)
            {
                var t = new Dictionary<string, object>
                {
                    { "tipo", "iphone" }, { "marca", "Apple" }, { "modelo", "iPhone" }, { "serial", null }, { "imei", null },
                    { "udid", d.Serial }, { "modo", "Normal" },
                };
                // el UDID del USB llega sin guiones; usbmuxd lo da con o sin guion según el modelo
                var m = lista.FirstOrDefault(x => Limpio(Plist.Texto(x, "SerialNumber")) == Limpio(d.Serial));
                if (m == null && lista.Count == 1) m = lista[0];
                if (m == null)
                {
                    // bloqueado: al menos el chip, que viene en el UDID (0000CPID-ECID)
                    var u = Regex.Match(d.Serial, @"^0000([0-9A-F]{4})-?([0-9A-F]{16})$", RegexOptions.IgnoreCase);
                    if (u.Success) t["modelo"] = Tablas.NombrePorChip(u.Groups[1].Value.ToUpperInvariant(), "");
                    t["aviso"] = fallo != null
                        ? "Instala iTunes o \"Dispositivos Apple\" de Microsoft Store para leer el modelo y la serie del iPhone."
                        : "Desbloquea el iPhone para leer su modelo.";
                    yield return t;
                    continue;
                }

                Dictionary<string, object> v = null;
                string error = null;
                try { v = Usbmux.Valores(Convert.ToInt32(m["DeviceID"]), d.Serial); }
                catch (Exception ex) { error = ex.Message; }
                if (v != null)
                {
                    string tipo = Plist.Texto(v, "ProductType");
                    t["modelo"] = Tablas.NombreIphone(tipo);
                    t["modelo_tecnico"] = tipo;
                    t["serial"] = Plist.Texto(v, "SerialNumber") == "" ? null : Plist.Texto(v, "SerialNumber");
                    string imei = Plist.Texto(v, "InternationalMobileEquipmentIdentity");
                    if (imei != "") t["imei"] = imei;
                    if (Plist.Texto(v, "ProductVersion") != "") t["sistema"] = "iOS " + Plist.Texto(v, "ProductVersion");
                    if (Plist.Texto(v, "DeviceName") != "") t["nombre"] = Plist.Texto(v, "DeviceName");
                    if (Plist.Texto(v, "ProductType").StartsWith("iPad")) t["tipo"] = "ipad";
                    string sesion = Plist.Texto(v, "_sesion");
                    if (t["serial"] == null)
                        t["aviso"] = sesion == "sin emparejamiento"
                            ? "Para leer la serie, conéctalo una vez con iTunes o 3uTools y toca \"Confiar en esta computadora\" en el iPhone."
                            : "No se pudo leer la serie: " + (sesion != "" ? sesion : "el iPhone no la dio") + ". Desbloquéalo y vuelve a intentar.";
                }
                else t["aviso"] = "No respondió el iPhone (" + error + "). Desbloquéalo y vuelve a intentar.";
                yield return t;
            }
        }

        static string Limpio(string s)
        {
            return (s ?? "").Replace("-", "").ToUpperInvariant();
        }

        /// <summary>Recovery / DFU: el identificador USB trae CPID, BDID, ECID y, en Recovery, la serie (SRNM).</summary>
        static Dictionary<string, object> Recuperacion(Pnp d, string modo)
        {
            string id = d.Id;
            string cpid = Campo(id, "CPID"), bdid = Campo(id, "BDID"), ecid = Campo(id, "ECID");
            string serie = Regex.Match(id, @"SRNM:\[([^\]]+)\]").Groups[1].Value;
            var t = new Dictionary<string, object>
            {
                { "tipo", "iphone" }, { "marca", "Apple" }, { "modelo", Tablas.NombrePorChip(cpid, bdid) },
                { "serial", serie == "" ? null : serie }, { "imei", null }, { "modo", modo },
                { "ecid", ecid }, { "cpid", cpid }, { "bdid", bdid },
            };
            if (serie == "") t["aviso"] = "En DFU no se ve la serie. Pon el equipo en modo Recovery para leerla.";
            return t;
        }

        static string Campo(string id, string nombre)
        {
            return Regex.Match(id, nombre + @":([0-9A-Fa-f]+)").Groups[1].Value.ToUpperInvariant();
        }

        // ------------------------------------------------------------------ Android

        /// <summary>El puerto COM del módem Samsung de este teléfono (interfaz "&MODEM" o clase Modem), o null.</summary>
        static string PuertoSamsung(Pnp d, List<Pnp> todos)
        {
            foreach (var x in todos.Where(x => x.Vid == d.Vid && x.Pid == d.Pid
                && (x.Clase == "Modem" || x.Id.IndexOf("&MODEM", StringComparison.OrdinalIgnoreCase) >= 0)))
            {
                string puerto = Samsung.Puerto(x.Id);
                if (!string.IsNullOrEmpty(puerto)) return puerto;
            }
            return null;
        }

        /// <summary>Samsung encendido: AT+DEVCONINFO por su módem da modelo de fábrica, serie, IMEI y firmware.</summary>
        static void LeerModemSamsung(Dictionary<string, object> t, Pnp d, List<Pnp> todos)
        {
            string puerto = PuertoSamsung(d, todos);
            if (puerto == null) return;
            var info = Samsung.Devconinfo(puerto);
            string v;
            if (info.TryGetValue("MN", out v) && v != "")
            {
                t["modelo_tecnico"] = v;
                if (string.IsNullOrEmpty(t["modelo"] as string)) t["modelo"] = v;
            }
            if (info.TryGetValue("SN", out v) && v != "") t["serial"] = v;
            if (info.TryGetValue("IMEI", out v) && Regex.IsMatch(v, @"^\d{14,16}$")) t["imei"] = v;
            if (info.TryGetValue("VER", out v) && v != "") t["firmware"] = v.Split('/')[0];
            if (info.ContainsKey("_ESTADO"))
                t["aviso"] = "El Samsung dijo que está ocupado: desbloquéalo y cierra SamFw, Odin u otros programas que lo estén usando, y vuelve a intentar.";
            else if (info.Count > 0) t["modo"] = t["modo"] + " + módem";
            else t["aviso"] = "No respondió el módem del Samsung (" + puerto + "): ciérralo en otros programas (Odin, UnlockTool…) y vuelve a intentar.";
        }

        /// <summary>
        /// Puertos de flasheo: MediaTek 0E8D:0003 (BROM), 2000 (Preloader), 2001 (DA); Qualcomm 05C6:9008 (EDL);
        /// Spreadtrum 1782:4D00 (descarga). En estos modos no se puede saber la marca real ni el modelo.
        /// </summary>
        static Dictionary<string, object> ModoServicio(Pnp d, List<Pnp> todos)
        {
            string modo = null;
            string nombre = string.Join(" ", todos.Where(x => x.Vid == d.Vid && x.Pid == d.Pid).Select(x => x.Nombre));
            if (d.Vid == "0E8D")
            {
                if (d.Pid == "0003") modo = "MediaTek BROM";
                else if (d.Pid == "2000" || nombre.IndexOf("PreLoader", StringComparison.OrdinalIgnoreCase) >= 0) modo = "MediaTek Preloader";
                else if (d.Pid == "2001") modo = "MediaTek DA";
                else if (nombre.IndexOf("MediaTek USB Port", StringComparison.OrdinalIgnoreCase) >= 0) modo = "MediaTek BROM/Preloader";
            }
            else if (d.Vid == "05C6" && (d.Pid == "9008" || nombre.IndexOf("QDLoader", StringComparison.OrdinalIgnoreCase) >= 0)) modo = "Qualcomm EDL (9008)";
            else if (d.Vid == "1782" && (d.Pid == "4D00" || nombre.IndexOf("SPRD", StringComparison.OrdinalIgnoreCase) >= 0)) modo = "Spreadtrum descarga";
            else if (d.Vid == "04E8" && d.Pid == "685D") modo = "Samsung Download (Odin)";
            if (modo == null) return null;
            // en Download de Samsung sí se sabe la marca; en los demás modos el chip no dice de qué marca es el teléfono
            string marca = d.Vid == "04E8" ? "Samsung" : null;

            string com = Regex.Match(nombre, @"\((COM\d+)\)").Groups[1].Value;
            if (com == "") com = Samsung.Puerto(d.Id) ?? PuertoSamsung(d, todos) ?? "";
            var t = new Dictionary<string, object>
            {
                { "tipo", "android" }, { "marca", marca }, { "modelo", null }, { "serial", null }, { "imei", null },
                { "modo", modo + (com != "" ? " (" + com + ")" : "") }, { "puerto", com == "" ? null : com },
                { "aviso", "En modo de flasheo el teléfono no dice su marca ni su modelo: captúralos a mano." },
            };
            if (marca == null) return t;

            // Samsung en Download: DVIF (comando de información de Odin, solo lectura) da modelo y firmware
            var info = com != "" ? Samsung.Dvif(com) : new Dictionary<string, string>();
            string v;
            if (info.TryGetValue("MODEL", out v) && v != "") { t["modelo"] = v; t["modelo_tecnico"] = v; }
            // VER es el firmware (A566EXXS6AYGE); FWVER solo es la versión del modo Descarga; CAPA, el almacenamiento en GB
            if (info.TryGetValue("VER", out v) && v != "") t["firmware"] = v;
            if (info.TryGetValue("CAPA", out v) && Regex.IsMatch(v, @"^\d+$")) t["almacenamiento"] = v + " GB";
            if (info.TryGetValue("SALES", out v) && v != "") t["region"] = v;
            if (info.TryGetValue("UN", out v) && v != "") t["un"] = v;
            t["aviso"] = info.Count > 0
                ? "En modo Download se lee el modelo, pero no la serie ni el IMEI: para esos, reinícialo normal."
                : "No respondió en modo Download" + (com != "" ? " (" + com + ")" : "") + ": cierra Odin u otros programas que lo estén usando.";
            return t;
        }

        static string NombreModelo(Pnp raiz, List<Pnp> todos)
        {
            // la interfaz MTP (clase WPD) trae el nombre comercial: "Galaxy A52s 5G", "Redmi Note 12"
            var mtp = todos.FirstOrDefault(x => x.Vid == raiz.Vid && x.Pid == raiz.Pid && x.Clase == "WPD" && x.Nombre != "");
            string nombre = mtp != null ? mtp.Nombre : raiz.Nombre;
            // nombres de controlador, no del teléfono ("Android ADB Interface", "USB Composite Device"…)
            if (Regex.IsMatch(nombre, @"^(USB|Composite|Dispositivo|ADB|Android|MTP|Interfaz)\b|ADB Interface|Composite ADB", RegexOptions.IgnoreCase)) return "";
            return nombre;
        }

        static string ModoAndroid(Pnp raiz, List<Pnp> todos)
        {
            var mismos = todos.Where(x => x.Vid == raiz.Vid && x.Pid == raiz.Pid).ToList();
            if (mismos.Any(x => x.Clase == "WPD")) return "MTP";
            if (mismos.Any(x => x.Nombre.IndexOf("ADB", StringComparison.OrdinalIgnoreCase) >= 0)) return "ADB";
            if (mismos.Any(x => x.Clase == "Modem" || x.Nombre.IndexOf("Modem", StringComparison.OrdinalIgnoreCase) >= 0)) return "Módem";
            return "Solo carga";
        }
    }

    /// <summary>Un dispositivo de Windows (Win32_PnPEntity) con su VID, PID y serie del identificador USB.</summary>
    class Pnp
    {
        public string Id, Nombre, Clase, Vid = "", Pid = "", Serial = "", Extra = "";
        public bool EsRaiz;

        public static List<Pnp> Listar()
        {
            var lista = new List<Pnp>();
            using (var q = new ManagementObjectSearcher("SELECT PNPDeviceID, Name, PNPClass FROM Win32_PnPEntity WHERE PNPDeviceID LIKE 'USB%'"))
            using (var res = q.Get())
                foreach (ManagementObject o in res)
                    using (o)
                    {
                        var p = new Pnp
                        {
                            Id = (o["PNPDeviceID"] as string) ?? "",
                            Nombre = ((o["Name"] as string) ?? "").Trim(),
                            Clase = (o["PNPClass"] as string) ?? "",
                        };
                        // VID_xxxx&PID_xxxx[&RESTORE_MODE...][&MI_nn]\instancia
                        var m = Regex.Match(p.Id, @"VID_([0-9A-F]{4})&PID_([0-9A-F]{4})((?:&[A-Z_]+)*?)(&MI_\d+)?\\(.+)$", RegexOptions.IgnoreCase);
                        if (!m.Success) continue;
                        p.Vid = m.Groups[1].Value.ToUpperInvariant();
                        p.Pid = m.Groups[2].Value.ToUpperInvariant();
                        // "&MODEM" (Samsung en Download) también es el equipo; las interfaces "&MI_nn" no
                        p.EsRaiz = !m.Groups[4].Success;
                        p.Extra = m.Groups[3].Value;
                        // si Windows inventó el identificador (trae "&") el teléfono no reportó serie
                        string ultimo = m.Groups[5].Value;
                        p.Serial = p.EsRaiz && ultimo.IndexOf('&') < 0 ? ultimo : "";
                        lista.Add(p);
                    }
            // la WPD del teléfono no siempre empieza con USB\: también se buscan por nombre
            using (var q = new ManagementObjectSearcher("SELECT PNPDeviceID, Name, PNPClass FROM Win32_PnPEntity WHERE PNPClass = 'WPD'"))
            using (var res = q.Get())
                foreach (ManagementObject o in res)
                    using (o)
                    {
                        string id = (o["PNPDeviceID"] as string) ?? "";
                        var m = Regex.Match(id, @"VID_([0-9A-F]{4})&PID_([0-9A-F]{4})", RegexOptions.IgnoreCase);
                        if (!m.Success || lista.Any(x => x.Id == id)) continue;
                        lista.Add(new Pnp
                        {
                            Id = id, Nombre = ((o["Name"] as string) ?? "").Trim(), Clase = "WPD",
                            Vid = m.Groups[1].Value.ToUpperInvariant(), Pid = m.Groups[2].Value.ToUpperInvariant(),
                        });
                    }
            return lista;
        }
    }

    /// <summary>
    /// Lo que sabe el servidor de adb que YA esté corriendo (el de Mamalonatl, UnlockTool, Android Studio…), preguntándole
    /// directo por su puerto (127.0.0.1:5037). Nunca se ejecuta adb.exe: un adb de otra versión cierra el servidor de las
    /// otras herramientas ("adb server version doesn't match… killing"). Si no hay servidor, no se usa adb.
    /// </summary>
    class AdbEquipo
    {
        public string Estado = "", Modelo = "", Comercial = "", Marca = "", Android = "", Imei = "";

        public static Dictionary<string, AdbEquipo> Listar()
        {
            var r = new Dictionary<string, AdbEquipo>(StringComparer.OrdinalIgnoreCase);
            string salida = Servidor.Pedir("host:devices-l");
            if (salida == null) return r;
            foreach (var linea in salida.Split('\n'))
            {
                var m = Regex.Match(linea.Trim(), @"^(\S+)\s+(device|unauthorized|offline|recovery|sideload)\b(.*)$");
                if (!m.Success) continue;
                var a = new AdbEquipo { Estado = m.Groups[2].Value };
                var mm = Regex.Match(m.Groups[3].Value, @"model:(\S+)");
                if (mm.Success) a.Modelo = mm.Groups[1].Value.Replace('_', '-');
                if (a.Estado == "device")
                {
                    string serie = m.Groups[1].Value;
                    a.Marca = Titulo(Prop(serie, "ro.product.brand"));
                    a.Android = Prop(serie, "ro.build.version.release");
                    // nombre comercial (Samsung, Xiaomi y otros lo guardan en alguna de estas)
                    foreach (var clave in new[] { "ro.product.marketname", "ro.product.vendor.marketname", "ro.vendor.oplus.market.name",
                        "ro.oppo.market.name", "ro.vivo.market.name", "ro.config.marketing_name", "ro.vendor.product.display" })
                    {
                        a.Comercial = Prop(serie, clave);
                        if (a.Comercial != "") break;
                    }
                    string modelo = Prop(serie, "ro.product.model");
                    if (modelo != "") a.Modelo = modelo;
                    a.Imei = LeerImei(serie);
                }
                r[m.Groups[1].Value] = a;
            }
            return r;
        }

        /// <summary>
        /// El IMEI por adb, en lo posible: algunos modelos lo dejan en propiedades del sistema y los Android viejos
        /// (hasta 9) lo dan con "service call iphonesubinfo". Android 10+ lo bloquea sin root: entonces queda vacío.
        /// Solo se acepta un número de 15 dígitos que pase la verificación de Luhn (nunca uno inventado).
        /// </summary>
        static string LeerImei(string serie)
        {
            foreach (var clave in new[] { "persist.radio.imei", "ro.gsm.imei", "ro.ril.oem.imei", "persist.sys.imei", "ril.imei", "persist.radio.imei1", "vendor.ril.imei" })
            {
                string v = Prop(serie, clave);
                if (EsImei(v)) return v;
            }
            foreach (var cmd in new[] { "service call iphonesubinfo 1 s16 com.android.shell", "service call iphonesubinfo 1", "service call iphonesubinfo 3 i32 0 s16 com.android.shell" })
            {
                string salida = Servidor.Shell(serie, cmd) ?? "";
                // "Result: Parcel(0x00000000: 00000000 0000000f 00350033 '........3.5.9.3.')": los dígitos van entre comillas
                var sb = new StringBuilder();
                foreach (Match q in Regex.Matches(salida, @"'([^']*)'"))
                    foreach (char ch in q.Groups[1].Value)
                        if (char.IsDigit(ch)) sb.Append(ch);
                string digitos = sb.ToString();
                if (digitos.Length >= 15 && EsImei(digitos.Substring(0, 15))) return digitos.Substring(0, 15);
            }
            return "";
        }

        public static bool EsImei(string v)
        {
            if (v == null || !Regex.IsMatch(v, @"^\d{15}$") || v.Distinct().Count() == 1) return false;
            int suma = 0;
            for (int i = 0; i < 15; i++)
            {
                int d = v[i] - '0';
                if (i % 2 == 1) { d *= 2; if (d > 9) d -= 9; }
                suma += d;
            }
            return suma % 10 == 0;
        }

        static string Prop(string serie, string clave)
        {
            return (Servidor.Shell(serie, "getprop " + clave) ?? "").Trim();
        }

        static string Titulo(string s)
        {
            return s.Length == 0 ? s : char.ToUpperInvariant(s[0]) + s.Substring(1);
        }

        /// <summary>Protocolo del servidor de adb: "LLLL" (largo en hexadecimal) + comando; responde OKAY o FAIL.</summary>
        static class Servidor
        {
            const int Puerto = 5037;

            /// <summary>Comando "host:" con respuesta de largo fijo (p. ej. host:devices-l). null si no hay servidor.</summary>
            public static string Pedir(string comando)
            {
                try
                {
                    using (var c = Conectar())
                    {
                        if (c == null) return null;
                        var red = c.GetStream();
                        if (!Enviar(red, comando)) return null;
                        int largo = Convert.ToInt32(Encoding.ASCII.GetString(Leer(red, 4)), 16);
                        return Encoding.UTF8.GetString(Leer(red, largo));
                    }
                }
                catch { return null; }
            }

            /// <summary>Comando de shell en un teléfono: se elige con host:transport y se lee hasta que cierre.</summary>
            public static string Shell(string serie, string comando)
            {
                try
                {
                    using (var c = Conectar())
                    {
                        if (c == null) return null;
                        var red = c.GetStream();
                        if (!Enviar(red, "host:transport:" + serie) || !Enviar(red, "shell:" + comando)) return null;
                        var ms = new System.IO.MemoryStream();
                        var b = new byte[4096];
                        int n;
                        while ((n = red.Read(b, 0, b.Length)) > 0) ms.Write(b, 0, n);
                        return Encoding.UTF8.GetString(ms.ToArray());
                    }
                }
                catch { return null; }
            }

            static System.Net.Sockets.TcpClient Conectar()
            {
                var c = new System.Net.Sockets.TcpClient();
                var intento = c.BeginConnect("127.0.0.1", Puerto, null, null);
                if (!intento.AsyncWaitHandle.WaitOne(700) || !c.Connected) { c.Close(); return null; }   // no hay servidor de adb
                c.EndConnect(intento);
                c.ReceiveTimeout = 4000;
                c.SendTimeout = 4000;
                return c;
            }

            static bool Enviar(System.Net.Sockets.NetworkStream red, string comando)
            {
                byte[] datos = Encoding.UTF8.GetBytes(comando);
                byte[] cab = Encoding.ASCII.GetBytes(datos.Length.ToString("X4"));
                red.Write(cab, 0, 4);
                red.Write(datos, 0, datos.Length);
                return Encoding.ASCII.GetString(Leer(red, 4)) == "OKAY";
            }

            static byte[] Leer(System.Net.Sockets.NetworkStream red, int n)
            {
                var b = new byte[n];
                int leido = 0;
                while (leido < n)
                {
                    int r = red.Read(b, leido, n - leido);
                    if (r <= 0) throw new System.IO.IOException("adb cerró la conexión");
                    leido += r;
                }
                return b;
            }
        }
    }
}
