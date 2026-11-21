using System;
using System.Collections.Generic;
using System.IO.Ports;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using Microsoft.Win32;

namespace RepaPOS.Conector
{
    /// <summary>
    /// El "SAMSUNG Mobile USB Modem" (puerto COM) del teléfono, sin adb ni otros programas:
    /// - Encendido (MTP): AT+DEVCONINFO da modelo de fábrica (MN), serie (SN), IMEI y firmware (VER).
    /// - Modo Descarga (Odin): DVIF da el modelo y el firmware.
    /// Son comandos de solo lectura.
    /// </summary>
    public static class Samsung
    {
        /// <summary>El puerto COM (p. ej. COM23) de una interfaz, según el registro de Windows.</summary>
        public static string Puerto(string instanciaPnp)
        {
            try
            {
                using (var k = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Enum\" + instanciaPnp + @"\Device Parameters"))
                    return k == null ? null : k.GetValue("PortName") as string;
            }
            catch { return null; }
        }

        /// <summary>Teléfono encendido: AT+DEVCONINFO. Devuelve los datos encontrados (vacío si no contestó).</summary>
        public static Dictionary<string, string> Devconinfo(string puerto)
        {
            var r = new Dictionary<string, string>();
            string resp = Pedir(puerto, "AT+DEVCONINFO\r\n",
                s => s.Contains("OK") && s.Contains("DEVCONINFO:") || s.Contains("ERROR") || s.Contains("BUSY"), 8000);
            if (resp == null) return r;
            // "BUSY": el teléfono está atendiendo a otro programa (SamFw, Odin…) o está bloqueado
            if (resp.Contains("BUSY")) { r["_ESTADO"] = "BUSY"; return r; }
            foreach (Match m in Regex.Matches(resp, @"(\w+)\(([^)]*)\)"))
                r[m.Groups[1].Value.ToUpperInvariant()] = m.Groups[2].Value.Trim();
            return r;
        }

        /// <summary>Modo Descarga: DVIF → "@#MODEL=SM-A566E;FWVER=…;SALES=…;…@#".</summary>
        public static Dictionary<string, string> Dvif(string puerto)
        {
            var r = new Dictionary<string, string>();
            string resp = Pedir(puerto, "DVIF", s => Regex.IsMatch(s, @"@#.*@#", RegexOptions.Singleline), 3000);
            if (resp == null) return r;
            var cuerpo = Regex.Match(resp, @"@#(.*)@#", RegexOptions.Singleline).Groups[1].Value;
            foreach (var par in cuerpo.Split(';'))
            {
                int i = par.IndexOf('=');
                if (i > 0) r[par.Substring(0, i).Trim().ToUpperInvariant()] = par.Substring(i + 1).Trim();
            }
            return r;
        }

        /// <summary>Abre el puerto, manda el comando y lee hasta que la respuesta esté completa o se acabe el tiempo.</summary>
        static string Pedir(string puerto, string comando, Func<string, bool> completa, int ms)
        {
            if (string.IsNullOrEmpty(puerto)) return null;
            try
            {
                using (var p = new SerialPort(puerto, 115200, Parity.None, 8, StopBits.One))
                {
                    p.ReadTimeout = 300;
                    p.WriteTimeout = 2000;
                    p.DtrEnable = true;
                    p.RtsEnable = true;
                    p.Encoding = Encoding.ASCII;
                    p.Open();
                    p.DiscardInBuffer();
                    p.Write(comando);
                    var sb = new StringBuilder();
                    var fin = DateTime.UtcNow.AddMilliseconds(ms);
                    while (DateTime.UtcNow < fin)
                    {
                        try { sb.Append(p.ReadExisting()); } catch (TimeoutException) { }
                        if (completa(sb.ToString())) break;
                        Thread.Sleep(80);
                    }
                    return sb.ToString();
                }
            }
            catch { return null; }   // ocupado por otro programa (Odin, UnlockTool…) o no responde
        }
    }
}
