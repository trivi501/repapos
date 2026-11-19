// RepaPOS Conector: detecta los teléfonos conectados por USB (Android por MTP/ADB, iPhone normal o en Recovery/DFU)
// y se los pasa a la página de RepaPOS en http://127.0.0.1:8765/telefonos (solo responde a esta PC).
// Compilar con compilar.bat (usa el csc.exe que ya trae Windows; C# 5).
using System;
using System.Drawing;
using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Threading;
using System.Windows.Forms;

namespace RepaPOS.Conector
{
    static class Programa
    {
        public const int Puerto = 8765;
        static TcpListener servidor;

        [STAThread]
        /// <summary>%LOCALAPPDATA%\RepaPOS\conector.log: por qué se cerró, si algún día se cierra solo.</summary>
        static void Anotar(string texto)
        {
            try
            {
                string dir = System.IO.Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "RepaPOS");
                System.IO.Directory.CreateDirectory(dir);
                System.IO.File.AppendAllText(System.IO.Path.Combine(dir, "conector.log"),
                    DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + "  " + texto + Environment.NewLine);
            }
            catch { }
        }

        static void Main(string[] args)
        {
            AppDomain.CurrentDomain.UnhandledException += (s, e) => Anotar("ERROR: " + e.ExceptionObject);
            Application.ThreadException += (s, e) => Anotar("ERROR: " + e.Exception);
            // modo consola para probar: RepaPOS-Conector.exe --listar
            if (args.Length > 0 && args[0] == "--listar")
            {
                Consola.Adjuntar();
                Console.WriteLine(Json.Escribir(Detector.Detectar(), true));
                return;
            }

            bool primera;
            using (var mutex = new Mutex(true, "RepaPOS_Conector", out primera))
            {
                if (!primera)
                {
                    MessageBox.Show("El conector de RepaPOS ya está abierto (junto al reloj).", "RepaPOS Conector",
                        MessageBoxButtons.OK, MessageBoxIcon.Information);
                    return;
                }
                // al reiniciarlo, el puerto puede tardar unos segundos en liberarse
                for (int intento = 1; servidor == null; intento++)
                {
                    try
                    {
                        servidor = new TcpListener(IPAddress.Loopback, Puerto);
                        servidor.Start();
                    }
                    catch (SocketException)
                    {
                        servidor = null;
                        if (intento < 8) { Thread.Sleep(1000); continue; }
                        MessageBox.Show("El puerto " + Puerto + " está ocupado por otro programa.", "RepaPOS Conector",
                            MessageBoxButtons.OK, MessageBoxIcon.Error);
                        return;
                    }
                }
                var hilo = new Thread(Atender) { IsBackground = true };
                hilo.Start();

                Application.EnableVisualStyles();
                var icono = new NotifyIcon
                {
                    Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath),
                    Text = "RepaPOS Conector: listo para detectar teléfonos",
                    Visible = true,
                    ContextMenu = new ContextMenu(new[]
                    {
                        new MenuItem("Ver teléfonos conectados", (s, e) => MostrarConectados()),
                        new MenuItem("-"),
                        new MenuItem("Salir", (s, e) => { Anotar("Cerrado desde el menú (Salir)."); Application.Exit(); }),
                    }),
                };
                icono.DoubleClick += (s, e) => MostrarConectados();
                icono.ShowBalloonTip(3000, "RepaPOS Conector", "Listo. Conecta el teléfono y presiona \"Detectar\" en RepaPOS.", ToolTipIcon.Info);
                Anotar("Abierto.");
                Application.Run();
                icono.Visible = false;
                servidor.Stop();
            }
        }

        static void MostrarConectados()
        {
            var lista = Detector.Detectar();
            var sb = new StringBuilder();
            foreach (var t in lista)
            {
                sb.AppendLine(Texto(t, "marca") + " " + Texto(t, "modelo") + "  (" + Texto(t, "modo") + ")");
                if (Texto(t, "serial") != "") sb.AppendLine("   Serie: " + Texto(t, "serial"));
                if (Texto(t, "imei") != "") sb.AppendLine("   IMEI: " + Texto(t, "imei"));
                if (Texto(t, "aviso") != "") sb.AppendLine("   " + Texto(t, "aviso"));
                sb.AppendLine();
            }
            MessageBox.Show(lista.Count == 0 ? "No hay teléfonos conectados." : sb.ToString(), "RepaPOS Conector");
        }

        static string Texto(System.Collections.Generic.Dictionary<string, object> d, string clave)
        {
            object v;
            return d.TryGetValue(clave, out v) && v != null ? v.ToString() : "";
        }

        // ------------------------------------------------------------------ HTTP mínimo (solo 127.0.0.1)

        static void Atender()
        {
            while (true)
            {
                TcpClient cliente;
                try { cliente = servidor.AcceptTcpClient(); }
                catch { return; }
                ThreadPool.QueueUserWorkItem(_ => Responder(cliente));
            }
        }

        /// <summary>
        /// Sitios a los que se les entregan los datos: RepaPOS en esta PC (localhost) y en el servidor (repapos.tech).
        /// Otros dominios se agregan, uno por renglón, en "origenes.txt" junto al conector (opcional).
        /// </summary>
        public static bool OrigenPermitido(string origen)
        {
            if (string.IsNullOrEmpty(origen)) return false;
            if (System.Text.RegularExpressions.Regex.IsMatch(origen, @"^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$", System.Text.RegularExpressions.RegexOptions.IgnoreCase)) return true;
            if (System.Text.RegularExpressions.Regex.IsMatch(origen, @"^https://([a-z0-9-]+\.)*repapos\.tech$", System.Text.RegularExpressions.RegexOptions.IgnoreCase)) return true;
            try
            {
                string archivo = System.IO.Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "origenes.txt");
                if (System.IO.File.Exists(archivo))
                    foreach (var l in System.IO.File.ReadAllLines(archivo))
                        if (l.Trim().TrimEnd('/').Equals(origen, StringComparison.OrdinalIgnoreCase)) return true;
            }
            catch { }
            return false;
        }

        /// <summary>
        /// Ventanita "puente" para cuando la página está en el servidor y el navegador no la deja hablar con esta PC
        /// (permiso de red local): esta página es del conector, lee los teléfonos y se los pasa a RepaPOS con postMessage.
        /// </summary>
        static string Puente(string ruta, out int estado)
        {
            estado = 200;
            var m = System.Text.RegularExpressions.Regex.Match(ruta, @"[?&]origen=([^&]+)");
            string origen = m.Success ? Uri.UnescapeDataString(m.Groups[1].Value) : "";
            if (!OrigenPermitido(origen))
            {
                estado = 403;
                return "<!doctype html><meta charset=utf-8><title>RepaPOS Conector</title><p>Este sitio no puede leer los teléfonos.</p>";
            }
            string destino = Json.Escribir(origen, false);   // ya validado; como texto JSON para el script
            return "<!doctype html><html><head><meta charset=utf-8><title>RepaPOS Conector</title>"
                + "<style>body{font:16px system-ui,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#111827;color:#fff}</style></head>"
                + "<body><p id=t>🔌 Leyendo el teléfono…</p><script>"
                + "var destino=" + destino + ";"
                + "fetch('/telefonos',{cache:'no-store'}).then(function(r){return r.json()})"
                + ".then(function(l){ if(window.opener){window.opener.postMessage({tipo:'repapos-telefonos',lista:l},destino);} window.close(); })"
                + ".catch(function(e){ document.getElementById('t').textContent='No se pudo leer: '+e; });"
                + "</script></body></html>";
        }

        static void Responder(TcpClient cliente)
        {
            using (cliente)
            {
                try
                {
                    cliente.ReceiveTimeout = 5000;
                    var red = cliente.GetStream();
                    var buffer = new byte[8192];
                    int n = red.Read(buffer, 0, buffer.Length);
                    if (n <= 0) return;
                    string peticion = Encoding.UTF8.GetString(buffer, 0, n);
                    string[] lineas = peticion.Split(new[] { "\r\n" }, StringSplitOptions.None);
                    string[] primera = lineas[0].Split(' ');
                    string metodo = primera[0], ruta = primera.Length > 1 ? primera[1] : "/";
                    string origen = null;
                    foreach (var l in lineas)
                        if (l.StartsWith("Origin:", StringComparison.OrdinalIgnoreCase)) origen = l.Substring(7).Trim();

                    int estado = 200;
                    string tipo = "application/json; charset=utf-8";
                    string cuerpo;
                    // los datos del teléfono (serie, IMEI) solo se entregan a RepaPOS: un sitio cualquiera no puede leerlos
                    if (origen != null && !OrigenPermitido(origen)) { estado = 403; cuerpo = "{\"message\":\"Sitio no permitido\"}"; }
                    else if (metodo == "OPTIONS") { estado = 204; cuerpo = ""; }
                    else if (ruta.StartsWith("/telefonos")) cuerpo = Json.Escribir(Detector.Detectar(), false);
                    else if (ruta.StartsWith("/puente")) { tipo = "text/html; charset=utf-8"; cuerpo = Puente(ruta, out estado); }
                    else if (ruta == "/" || ruta.StartsWith("/estado")) cuerpo = "{\"ok\":true,\"programa\":\"RepaPOS Conector\",\"version\":2}";
                    else { estado = 404; cuerpo = "{\"message\":\"No existe\"}"; }

                    byte[] datos = Encoding.UTF8.GetBytes(cuerpo);
                    string cabecera = "HTTP/1.1 " + estado + (estado == 200 ? " OK" : estado == 204 ? " No Content" : estado == 403 ? " Forbidden" : " Not Found") + "\r\n"
                        + "Content-Type: " + tipo + "\r\n"
                        + "Content-Length: " + datos.Length + "\r\n"
                        + (origen != null && OrigenPermitido(origen)
                            ? "Access-Control-Allow-Origin: " + origen + "\r\nVary: Origin\r\n"
                              + "Access-Control-Allow-Methods: GET, OPTIONS\r\n"
                              + "Access-Control-Allow-Headers: *\r\n"
                              + "Access-Control-Allow-Private-Network: true\r\n"
                            : "")
                        + "Cache-Control: no-store\r\n"
                        + "Connection: close\r\n\r\n";
                    byte[] cab = Encoding.ASCII.GetBytes(cabecera);
                    red.Write(cab, 0, cab.Length);
                    red.Write(datos, 0, datos.Length);
                }
                catch { }
            }
        }
    }

    /// <summary>Para el modo --listar: escribe en la consola desde la que se abrió.</summary>
    static class Consola
    {
        [System.Runtime.InteropServices.DllImport("kernel32.dll")]
        static extern bool AttachConsole(int pid);

        public static void Adjuntar()
        {
            AttachConsole(-1);
            Console.OutputEncoding = Encoding.UTF8;
        }
    }
}
