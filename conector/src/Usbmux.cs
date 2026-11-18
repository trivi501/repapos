using System;
using System.Collections.Generic;
using System.IO;
using System.Net.Sockets;
using System.Text;

namespace RepaPOS.Conector
{
    /// <summary>
    /// Habla con el servicio de Apple que instalan iTunes o "Dispositivos Apple" (usbmuxd, 127.0.0.1:27015):
    /// lista los iPhone conectados y le pregunta a cada uno (lockdownd) su modelo, serie e iOS.
    /// Sin "Confiar en esta computadora" el iPhone solo contesta algunos datos (casi siempre el modelo).
    /// </summary>
    public static class Usbmux
    {
        const int PuertoUsbmux = 27015;
        const int PuertoLockdown = 62078;

        public static List<Dictionary<string, object>> Dispositivos()
        {
            using (var c = Conectar())
            {
                var r = Pedir(c.GetStream(), Mensaje("ListDevices"));
                var lista = new List<Dictionary<string, object>>();
                var dispositivos = r.ContainsKey("DeviceList") ? r["DeviceList"] as List<object> : null;
                if (dispositivos == null) return lista;
                foreach (var o in dispositivos)
                {
                    var d = o as Dictionary<string, object>;
                    var props = d != null && d.ContainsKey("Properties") ? d["Properties"] as Dictionary<string, object> : null;
                    if (props == null) continue;
                    if (Plist.Texto(props, "ConnectionType") != "" && Plist.Texto(props, "ConnectionType") != "USB") continue;
                    lista.Add(props);
                }
                return lista;
            }
        }

        /// <summary>
        /// Lo que lockdownd da sin sesión (modelo, iOS) y, si Windows tiene el emparejamiento de este iPhone,
        /// lo que da dentro de la sesión cifrada (serie, IMEI). Si la sesión falla, "_sesion" dice por qué.
        /// </summary>
        public static Dictionary<string, object> Valores(int dispositivo, string udid)
        {
            using (var c = Conectar())
            {
                var red = c.GetStream();
                // el puerto va en orden de red (big-endian) dentro de un entero
                int puerto = ((PuertoLockdown & 0xFF) << 8) | (PuertoLockdown >> 8);
                var r = Pedir(red, Mensaje("Connect", "<key>DeviceID</key><integer>" + dispositivo + "</integer><key>PortNumber</key><integer>" + puerto + "</integer>"));
                if (Plist.Texto(r, "Number") != "0") throw new IOException("el iPhone no aceptó la conexión (código " + Plist.Texto(r, "Number") + ")");

                // desde aquí el mismo socket es un túnel a lockdownd: 4 bytes de largo (big-endian) + plist
                EnviarLockdown(red, "<key>Label</key><string>RepaPOS</string><key>Request</key><string>GetValue</string>");
                var resp = RecibirLockdown(red);
                var valores = resp.ContainsKey("Value") ? resp["Value"] as Dictionary<string, object> : null;
                if (valores == null) throw new IOException(Plist.Texto(resp, "Error") != "" ? Plist.Texto(resp, "Error") : "sin respuesta");

                try
                {
                    var registro = Sesion.Emparejamiento(Plist.Texto(valores, "UniqueDeviceID") != "" ? Plist.Texto(valores, "UniqueDeviceID") : udid);
                    if (registro == null) valores["_sesion"] = "sin emparejamiento";
                    else foreach (var kv in Sesion.Valores(red, registro)) valores[kv.Key] = kv.Value;
                }
                catch (Exception ex) { valores["_sesion"] = ex.Message; }
                return valores;
            }
        }

        static TcpClient Conectar()
        {
            var c = new TcpClient();
            var intento = c.BeginConnect("127.0.0.1", PuertoUsbmux, null, null);
            if (!intento.AsyncWaitHandle.WaitOne(1500) || !c.Connected)
            {
                c.Close();
                throw new IOException("no está instalado el servicio de Apple (iTunes o Dispositivos Apple)");
            }
            c.EndConnect(intento);
            c.ReceiveTimeout = 5000;
            c.SendTimeout = 5000;
            return c;
        }

        static string Mensaje(string tipo, string extra = "")
        {
            return "<key>MessageType</key><string>" + tipo + "</string>"
                + "<key>ClientVersionString</key><string>RepaPOS-Conector</string><key>ProgName</key><string>RepaPOS</string>" + extra;
        }

        static string Documento(string contenidoDict)
        {
            return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE plist PUBLIC \"-//Apple//DTD PLIST 1.0//EN\" "
                + "\"http://www.apple.com/DTDs/PropertyList-1.0.dtd\">\n<plist version=\"1.0\"><dict>" + contenidoDict + "</dict></plist>\n";
        }

        /// <summary>usbmuxd: cabecera de 16 bytes little-endian (largo, versión 1, mensaje 8 = plist, etiqueta) + plist.</summary>
        static Dictionary<string, object> Pedir(NetworkStream red, string contenidoDict)
        {
            byte[] cuerpo = Encoding.UTF8.GetBytes(Documento(contenidoDict));
            var cab = new byte[16];
            BitConverter.GetBytes(16 + cuerpo.Length).CopyTo(cab, 0);
            BitConverter.GetBytes(1).CopyTo(cab, 4);
            BitConverter.GetBytes(8).CopyTo(cab, 8);
            BitConverter.GetBytes(1).CopyTo(cab, 12);
            red.Write(cab, 0, 16);
            red.Write(cuerpo, 0, cuerpo.Length);

            byte[] cabResp = Leer(red, 16);
            int largo = BitConverter.ToInt32(cabResp, 0);
            return Plist.Leer(Encoding.UTF8.GetString(Leer(red, largo - 16)));
        }

        public static void EnviarLockdown(Stream red, string contenidoDict)
        {
            byte[] cuerpo = Encoding.UTF8.GetBytes(Documento(contenidoDict));
            byte[] largo = BitConverter.GetBytes(cuerpo.Length);
            Array.Reverse(largo);
            red.Write(largo, 0, 4);
            red.Write(cuerpo, 0, cuerpo.Length);
            red.Flush();
        }

        public static Dictionary<string, object> RecibirLockdown(Stream red)
        {
            byte[] largo = Leer(red, 4);
            Array.Reverse(largo);
            int n = BitConverter.ToInt32(largo, 0);
            if (n <= 0 || n > 4 * 1024 * 1024) throw new IOException("respuesta inválida del iPhone");
            return Plist.Leer(Encoding.UTF8.GetString(Leer(red, n)));
        }

        static byte[] Leer(Stream red, int n)
        {
            var b = new byte[n];
            int leido = 0;
            while (leido < n)
            {
                int r = red.Read(b, leido, n - leido);
                if (r <= 0) throw new IOException("se cortó la conexión con el iPhone");
                leido += r;
            }
            return b;
        }
    }
}
