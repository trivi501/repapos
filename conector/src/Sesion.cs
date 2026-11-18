using System;
using System.Collections.Generic;
using System.IO;
using System.Net.Security;
using System.Security.Authentication;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text;

namespace RepaPOS.Conector
{
    /// <summary>
    /// Sesión cifrada con el iPhone usando el emparejamiento que Windows ya tiene guardado
    /// (C:\ProgramData\Apple\Lockdown\UDID.plist, lo crean iTunes, 3uTools, etc. cuando el iPhone "confía" en la PC).
    /// Dentro de la sesión el iPhone sí da la serie y el IMEI. El conector no crea emparejamientos nuevos.
    /// </summary>
    public static class Sesion
    {
        const string Carpeta = @"C:\ProgramData\Apple\Lockdown";

        /// <summary>El emparejamiento guardado para este UDID (con o sin guion), o null.</summary>
        public static Dictionary<string, object> Emparejamiento(string udid)
        {
            if (string.IsNullOrEmpty(udid)) return null;
            string limpio = udid.Replace("-", "").ToUpperInvariant();
            var nombres = new List<string> { udid };
            if (limpio.Length == 24) nombres.Add(limpio.Substring(0, 8) + "-" + limpio.Substring(8));   // 00008030-001649D12EF1802E
            nombres.Add(limpio);
            nombres.Add(limpio.ToLowerInvariant());   // los modelos viejos usan 40 caracteres hexadecimales
            foreach (var n in nombres)
            {
                string archivo = Path.Combine(Carpeta, n + ".plist");
                try
                {
                    if (File.Exists(archivo)) return Plist.Leer(File.ReadAllText(archivo, Encoding.UTF8));
                }
                catch (UnauthorizedAccessException) { throw new IOException("Windows no deja leer el emparejamiento del iPhone"); }
            }
            return null;
        }

        /// <summary>Abre la sesión (StartSession + TLS) sobre la conexión a lockdownd ya abierta y pide todos los valores.</summary>
        public static Dictionary<string, object> Valores(Stream lockdown, Dictionary<string, object> registro)
        {
            string hostId = Plist.Texto(registro, "HostID"), buid = Plist.Texto(registro, "SystemBUID");
            Usbmux.EnviarLockdown(lockdown, "<key>Label</key><string>RepaPOS</string><key>Request</key><string>StartSession</string>"
                + "<key>HostID</key><string>" + hostId + "</string><key>SystemBUID</key><string>" + buid + "</string>");
            var r = Usbmux.RecibirLockdown(lockdown);
            if (Plist.Texto(r, "Error") != "")
                throw new IOException(Plist.Texto(r, "Error") == "InvalidHostID"
                    ? "el emparejamiento ya no vale (el iPhone se restauró o se le quitó la confianza)"
                    : "el iPhone no abrió la sesión (" + Plist.Texto(r, "Error") + ")");

            Stream canal = lockdown;
            SslStream ssl = null;
            if (r.ContainsKey("EnableSessionSSL") && r["EnableSessionSSL"] is bool && (bool)r["EnableSessionSSL"])
            {
                var cert = Certificado(registro);
                ssl = new SslStream(lockdown, true, (s, c, ch, e) => true, (s, host, locales, remoto, emisores) => cert);
                ssl.AuthenticateAsClient("iPhone", new X509CertificateCollection { cert }, (SslProtocols)3072 | (SslProtocols)768, false);
                canal = ssl;
            }
            try
            {
                Usbmux.EnviarLockdown(canal, "<key>Label</key><string>RepaPOS</string><key>Request</key><string>GetValue</string>");
                var resp = Usbmux.RecibirLockdown(canal);
                var valores = resp.ContainsKey("Value") ? resp["Value"] as Dictionary<string, object> : null;
                if (valores == null) throw new IOException("el iPhone no contestó dentro de la sesión (" + Plist.Texto(resp, "Error") + ")");
                return valores;
            }
            finally
            {
                if (ssl != null) ssl.Dispose();
            }
        }

        /// <summary>El certificado de la PC (HostCertificate) con su llave privada (HostPrivateKey), ambos en PEM.</summary>
        static X509Certificate2 Certificado(Dictionary<string, object> registro)
        {
            byte[] certPem = registro.ContainsKey("HostCertificate") ? registro["HostCertificate"] as byte[] : null;
            byte[] llavePem = registro.ContainsKey("HostPrivateKey") ? registro["HostPrivateKey"] as byte[] : null;
            if (certPem == null || llavePem == null) throw new IOException("el emparejamiento no trae el certificado de la PC");

            var cert = new X509Certificate2(Der(Encoding.ASCII.GetString(certPem)));
            var p = LlaveRsa(Der(Encoding.ASCII.GetString(llavePem)));
            // SChannel necesita la llave en un contenedor con nombre (uno fijo para no llenar la PC de llaves)
            var csp = new CspParameters { KeyContainerName = "RepaPOS-Conector-" + Plist.Texto(registro, "HostID") };
            var rsa = new RSACryptoServiceProvider(csp) { PersistKeyInCsp = true };
            rsa.ImportParameters(p);
            cert.PrivateKey = rsa;
            return cert;
        }

        static byte[] Der(string pem)
        {
            var sb = new StringBuilder();
            foreach (var linea in pem.Split('\n'))
            {
                string l = linea.Trim();
                if (l.Length == 0 || l.StartsWith("-----")) continue;
                sb.Append(l);
            }
            return Convert.FromBase64String(sb.ToString());
        }

        // ------------------------------------------------------------------ llave RSA (PKCS#1 o PKCS#8, DER)

        static RSAParameters LlaveRsa(byte[] der)
        {
            int pos = 0;
            var seq = Leer(der, ref pos, 0x30);
            int p = 0;
            var version = Leer(seq, ref p, 0x02);
            // PKCS#8: versión, algoritmo (SEQUENCE) y la llave PKCS#1 dentro de un OCTET STRING
            if (p < seq.Length && seq[p] == 0x30)
            {
                Leer(seq, ref p, 0x30);
                return LlaveRsa(Leer(seq, ref p, 0x04));
            }
            var n = Leer(seq, ref p, 0x02); var e = Leer(seq, ref p, 0x02); var d = Leer(seq, ref p, 0x02);
            var p1 = Leer(seq, ref p, 0x02); var q = Leer(seq, ref p, 0x02);
            var dp = Leer(seq, ref p, 0x02); var dq = Leer(seq, ref p, 0x02); var iq = Leer(seq, ref p, 0x02);
            n = SinCeros(n);
            int k = n.Length, m = (k + 1) / 2;
            return new RSAParameters
            {
                Modulus = n, Exponent = SinCeros(e), D = Ajustar(d, k),
                P = Ajustar(p1, m), Q = Ajustar(q, m), DP = Ajustar(dp, m), DQ = Ajustar(dq, m), InverseQ = Ajustar(iq, m),
            };
        }

        static byte[] Leer(byte[] b, ref int pos, int etiqueta)
        {
            if (b[pos] != etiqueta) throw new IOException("la llave del emparejamiento tiene un formato desconocido");
            pos++;
            int largo = b[pos++];
            if ((largo & 0x80) != 0)
            {
                int bytes = largo & 0x7F;
                largo = 0;
                for (int i = 0; i < bytes; i++) largo = (largo << 8) | b[pos++];
            }
            var v = new byte[largo];
            Array.Copy(b, pos, v, 0, largo);
            pos += largo;
            return v;
        }

        static byte[] SinCeros(byte[] v)
        {
            int i = 0;
            while (i < v.Length - 1 && v[i] == 0) i++;
            var r = new byte[v.Length - i];
            Array.Copy(v, i, r, 0, r.Length);
            return r;
        }

        /// <summary>RSAParameters pide largos exactos: sin ceros a la izquierda y rellenado hasta el largo.</summary>
        static byte[] Ajustar(byte[] v, int largo)
        {
            v = SinCeros(v);
            if (v.Length >= largo) return v;
            var r = new byte[largo];
            Array.Copy(v, 0, r, largo - v.Length, v.Length);
            return r;
        }
    }
}
