using System;
using System.Collections;
using System.Collections.Generic;
using System.Globalization;
using System.Text;
using System.Xml;

namespace RepaPOS.Conector
{
    /// <summary>Lee los plist XML de Apple: dict, array, string, integer, real, true/false, date y data.</summary>
    public static class Plist
    {
        public static Dictionary<string, object> Leer(string xml)
        {
            var doc = new XmlDocument { XmlResolver = null };
            var opciones = new XmlReaderSettings { DtdProcessing = DtdProcessing.Ignore, XmlResolver = null };
            using (var r = XmlReader.Create(new System.IO.StringReader(xml), opciones)) doc.Load(r);
            var raiz = doc.SelectSingleNode("/plist/*");
            return (raiz != null ? Valor(raiz) : null) as Dictionary<string, object> ?? new Dictionary<string, object>();
        }

        static object Valor(XmlNode n)
        {
            switch (n.Name)
            {
                case "dict":
                    var d = new Dictionary<string, object>();
                    string clave = null;
                    foreach (XmlNode h in n.ChildNodes)
                    {
                        if (h.NodeType != XmlNodeType.Element) continue;
                        if (h.Name == "key") clave = h.InnerText;
                        else if (clave != null) { d[clave] = Valor(h); clave = null; }
                    }
                    return d;
                case "array":
                    var a = new List<object>();
                    foreach (XmlNode h in n.ChildNodes) if (h.NodeType == XmlNodeType.Element) a.Add(Valor(h));
                    return a;
                case "integer":
                    long l;
                    return long.TryParse(n.InnerText.Trim(), NumberStyles.Integer, CultureInfo.InvariantCulture, out l) ? (object)l : n.InnerText;
                case "real":
                    double x;
                    return double.TryParse(n.InnerText.Trim(), NumberStyles.Float, CultureInfo.InvariantCulture, out x) ? (object)x : n.InnerText;
                case "true": return true;
                case "false": return false;
                case "data":
                    var b64 = new StringBuilder();
                    foreach (char c in n.InnerText) if (!char.IsWhiteSpace(c)) b64.Append(c);
                    try { return Convert.FromBase64String(b64.ToString()); }
                    catch (FormatException) { return new byte[0]; }
                default: return n.InnerText;   // string, date
            }
        }

        public static string Texto(Dictionary<string, object> d, string clave)
        {
            object v;
            if (d == null || !d.TryGetValue(clave, out v) || v == null) return "";
            return Convert.ToString(v, CultureInfo.InvariantCulture);
        }
    }

    /// <summary>JSON mínimo para la respuesta: diccionarios, listas, texto, números, bool y null.</summary>
    public static class Json
    {
        public static string Escribir(object v, bool bonito)
        {
            var sb = new StringBuilder();
            Escribir(sb, v, bonito, 0);
            return sb.ToString();
        }

        static void Escribir(StringBuilder sb, object v, bool bonito, int nivel)
        {
            string salto = bonito ? "\n" + new string(' ', (nivel + 1) * 2) : "";
            string cierre = bonito ? "\n" + new string(' ', nivel * 2) : "";
            if (v == null) sb.Append("null");
            else if (v is string) Cadena(sb, (string)v);
            else if (v is bool) sb.Append((bool)v ? "true" : "false");
            else if (v is int || v is long || v is double) sb.Append(Convert.ToString(v, CultureInfo.InvariantCulture));
            else if (v is byte[]) sb.Append("null");   // datos binarios (certificados): nunca salen en la respuesta
            else if (v is IDictionary<string, object>)
            {
                var d = (IDictionary<string, object>)v;
                sb.Append('{');
                bool primero = true;
                foreach (var kv in d)
                {
                    if (!primero) sb.Append(',');
                    primero = false;
                    sb.Append(salto);
                    Cadena(sb, kv.Key);
                    sb.Append(bonito ? ": " : ":");
                    Escribir(sb, kv.Value, bonito, nivel + 1);
                }
                if (!primero) sb.Append(cierre);
                sb.Append('}');
            }
            else if (v is IEnumerable)
            {
                sb.Append('[');
                bool primero = true;
                foreach (var o in (IEnumerable)v)
                {
                    if (!primero) sb.Append(',');
                    primero = false;
                    sb.Append(salto);
                    Escribir(sb, o, bonito, nivel + 1);
                }
                if (!primero) sb.Append(cierre);
                sb.Append(']');
            }
            else Cadena(sb, v.ToString());
        }

        static void Cadena(StringBuilder sb, string s)
        {
            sb.Append('"');
            foreach (char c in s)
            {
                switch (c)
                {
                    case '"': sb.Append("\\\""); break;
                    case '\\': sb.Append("\\\\"); break;
                    case '\n': sb.Append("\\n"); break;
                    case '\r': sb.Append("\\r"); break;
                    case '\t': sb.Append("\\t"); break;
                    default:
                        if (c < ' ') sb.Append("\\u").Append(((int)c).ToString("x4"));
                        else sb.Append(c);
                        break;
                }
            }
            sb.Append('"');
        }
    }

    /// <summary>Nombres comerciales de los iPhone: por ProductType (encendido) o por chip CPID/BDID (Recovery/DFU).</summary>
    public static class Tablas
    {
        static readonly Dictionary<string, string> PorTipo = new Dictionary<string, string>
        {
            { "iPhone8,1", "iPhone 6s" }, { "iPhone8,2", "iPhone 6s Plus" }, { "iPhone8,4", "iPhone SE (1.ª gen.)" },
            { "iPhone9,1", "iPhone 7" }, { "iPhone9,3", "iPhone 7" }, { "iPhone9,2", "iPhone 7 Plus" }, { "iPhone9,4", "iPhone 7 Plus" },
            { "iPhone10,1", "iPhone 8" }, { "iPhone10,4", "iPhone 8" }, { "iPhone10,2", "iPhone 8 Plus" }, { "iPhone10,5", "iPhone 8 Plus" },
            { "iPhone10,3", "iPhone X" }, { "iPhone10,6", "iPhone X" },
            { "iPhone11,2", "iPhone XS" }, { "iPhone11,4", "iPhone XS Max" }, { "iPhone11,6", "iPhone XS Max" }, { "iPhone11,8", "iPhone XR" },
            { "iPhone12,1", "iPhone 11" }, { "iPhone12,3", "iPhone 11 Pro" }, { "iPhone12,5", "iPhone 11 Pro Max" }, { "iPhone12,8", "iPhone SE (2.ª gen.)" },
            { "iPhone13,1", "iPhone 12 mini" }, { "iPhone13,2", "iPhone 12" }, { "iPhone13,3", "iPhone 12 Pro" }, { "iPhone13,4", "iPhone 12 Pro Max" },
            { "iPhone14,4", "iPhone 13 mini" }, { "iPhone14,5", "iPhone 13" }, { "iPhone14,2", "iPhone 13 Pro" }, { "iPhone14,3", "iPhone 13 Pro Max" },
            { "iPhone14,6", "iPhone SE (3.ª gen.)" }, { "iPhone14,7", "iPhone 14" }, { "iPhone14,8", "iPhone 14 Plus" },
            { "iPhone15,2", "iPhone 14 Pro" }, { "iPhone15,3", "iPhone 14 Pro Max" }, { "iPhone15,4", "iPhone 15" }, { "iPhone15,5", "iPhone 15 Plus" },
            { "iPhone16,1", "iPhone 15 Pro" }, { "iPhone16,2", "iPhone 15 Pro Max" },
            { "iPhone17,3", "iPhone 16" }, { "iPhone17,4", "iPhone 16 Plus" }, { "iPhone17,1", "iPhone 16 Pro" }, { "iPhone17,2", "iPhone 16 Pro Max" },
            { "iPhone17,5", "iPhone 16e" },
            { "iPhone18,1", "iPhone 17 Pro" }, { "iPhone18,2", "iPhone 17 Pro Max" }, { "iPhone18,3", "iPhone 17" }, { "iPhone18,4", "iPhone Air" },
        };

        // chip (CPID) + placa (BDID) en Recovery/DFU; los que no están aquí se muestran por su chip
        static readonly Dictionary<string, string> PorChip = new Dictionary<string, string>
        {
            { "8020:0E", "iPhone XS" }, { "8020:0A", "iPhone XS Max" }, { "8020:1A", "iPhone XS Max" }, { "8020:0C", "iPhone XR" },
            { "8030:04", "iPhone 11" }, { "8030:06", "iPhone 11 Pro" }, { "8030:02", "iPhone 11 Pro Max" }, { "8030:10", "iPhone SE (2.ª gen.)" },
            { "8101:0A", "iPhone 12 mini" }, { "8101:0C", "iPhone 12" }, { "8101:0E", "iPhone 12 Pro" }, { "8101:08", "iPhone 12 Pro Max" },
            { "8110:0A", "iPhone 13 mini" }, { "8110:0C", "iPhone 13" }, { "8110:0E", "iPhone 13 Pro" }, { "8110:08", "iPhone 13 Pro Max" },
            { "8110:10", "iPhone SE (3.ª gen.)" }, { "8110:18", "iPhone 14" }, { "8110:1A", "iPhone 14 Plus" },
            { "8120:0C", "iPhone 14 Pro" }, { "8120:0E", "iPhone 14 Pro Max" }, { "8120:08", "iPhone 15" }, { "8120:0A", "iPhone 15 Plus" },
            { "8130:04", "iPhone 15 Pro" }, { "8130:0C", "iPhone 15 Pro Max" },
        };

        static readonly Dictionary<string, string> Chips = new Dictionary<string, string>
        {
            { "8000", "A9" }, { "8003", "A9" }, { "8010", "A10" }, { "8015", "A11" }, { "8020", "A12" }, { "8030", "A13" },
            { "8101", "A14" }, { "8110", "A15" }, { "8120", "A16" }, { "8130", "A17 Pro" }, { "8140", "A18" }, { "8150", "A19" },
        };

        public static string NombreIphone(string tipo)
        {
            string n;
            return tipo == "" ? "iPhone" : PorTipo.TryGetValue(tipo, out n) ? n : tipo;
        }

        public static string NombrePorChip(string cpid, string bdid)
        {
            string n, chip;
            if (bdid.Length == 1) bdid = "0" + bdid;
            if (PorChip.TryGetValue(cpid + ":" + bdid, out n)) return n;
            if (!Chips.TryGetValue(cpid, out chip)) return cpid == "" ? "iPhone" : "iPhone (chip " + cpid + ")";
            return "iPhone con chip " + chip + (bdid != "" ? " (placa " + bdid + ")" : "");
        }
    }
}
