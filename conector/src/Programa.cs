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
        static void Main(string[] args)
        {
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
                try
                {
                    servidor = new TcpListener(IPAddress.Loopback, Puerto);
                    servidor.Start();
                }
                catch (SocketException)
                {
                    MessageBox.Show("El puerto " + Puerto + " está ocupado por otro programa.", "RepaPOS Conector",
                        MessageBoxButtons.OK, MessageBoxIcon.Error);
                    return;
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
                        new MenuItem("Salir", (s, e) => Application.Exit()),
                    }),
                };
                icono.DoubleClick += (s, e) => MostrarConectados();
                icono.ShowBalloonTip(3000, "RepaPOS Conector", "Listo. Conecta el teléfono y presiona \"Detectar\" en RepaPOS.", ToolTipIcon.Info);
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
                    string peticion = Encoding.ASCII.GetString(buffer, 0, n);
                    string[] primera = peticion.Split(new[] { "\r\n" }, StringSplitOptions.None)[0].Split(' ');
                    string metodo = primera[0], ruta = primera.Length > 1 ? primera[1] : "/";

                    int estado = 200;
                    string cuerpo;
                    if (metodo == "OPTIONS") { estado = 204; cuerpo = ""; }
                    else if (ruta.StartsWith("/telefonos")) cuerpo = Json.Escribir(Detector.Detectar(), false);
                    else if (ruta == "/" || ruta.StartsWith("/estado")) cuerpo = "{\"ok\":true,\"programa\":\"RepaPOS Conector\",\"version\":1}";
                    else { estado = 404; cuerpo = "{\"message\":\"No existe\"}"; }

                    byte[] datos = Encoding.UTF8.GetBytes(cuerpo);
                    string cabecera = "HTTP/1.1 " + estado + (estado == 200 ? " OK" : estado == 204 ? " No Content" : " Not Found") + "\r\n"
                        + "Content-Type: application/json; charset=utf-8\r\n"
                        + "Content-Length: " + datos.Length + "\r\n"
                        // la página de RepaPOS (localhost o el dominio del taller) puede leerlo; solo escucha en esta PC
                        + "Access-Control-Allow-Origin: *\r\n"
                        + "Access-Control-Allow-Methods: GET, OPTIONS\r\n"
                        + "Access-Control-Allow-Headers: *\r\n"
                        + "Access-Control-Allow-Private-Network: true\r\n"
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
