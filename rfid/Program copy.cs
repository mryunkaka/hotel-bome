// HotelBome RFID Bridge — SIMPLE: Idle until /scan; Reset→Read→Reset inside handler
// - Tidak ada warmup/engine di startup → LED biru diam.
// - Saat /scan: lakukan SoftReset() → loop baca (rising-edge only) dengan timing seperti DLock → SoftReset() lagi.
// - Poll ≈15ms, active tick jarang (≈200ms) agar blink lambat.
// - C#5 compatible; tanpa 'volatile DateTime' & tanpa thread engine.

using System;
using System.IO;
using System.Net;
using System.Text;
using System.Threading;
using System.Runtime.InteropServices;
using System.Diagnostics;

namespace HotelBome.RFID
{
    internal class Program
    {
        [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
        static extern bool SetDllDirectory(string lpPathName);

        // === Vendor SDK ===
        [DllImport("LockSDK.dll", CallingConvention = CallingConvention.StdCall, CharSet = CharSet.Ansi)]
        static extern int TP_Configuration(int lock_type);
        [DllImport("LockSDK.dll", CallingConvention = CallingConvention.StdCall, CharSet = CharSet.Ansi)]
        static extern int TP_M1Active(StringBuilder card_snr);
        [DllImport("LockSDK.dll", CallingConvention = CallingConvention.StdCall, CharSet = CharSet.Ansi)]
        static extern int TP_GetCardSnr(StringBuilder card_snr);

        // Optional beeps
        [DllImport("LockSDK.dll", EntryPoint = "TP_Beep", CallingConvention = CallingConvention.StdCall)]
        static extern int TP_Beep_Optional(int ms);
        [DllImport("RC500USB.dll", EntryPoint = "RC500Beep", CallingConvention = CallingConvention.StdCall)]
        static extern int RC500Beep_Optional(int times);
        [DllImport("PubFuns.dll", EntryPoint = "DevBeep", CallingConvention = CallingConvention.StdCall)]
        static extern int DevBeep_Optional(int ms);

        // ===== Config (disederhanakan) =====
        class Config
        {
            public string LaravelApi = "http://hotel-bome.test";
            public string BridgeToken = "hotelbome-bridge-2025";
            public int Port = 8200;

            public string LockTypes = "5";
            public int LockType = 5;

            public int TIMEOUT_MS = 8000;

            // Timing meniru DLock (≈ IAT 14–15ms), blink lambat:
            public int POLL_MS = 15;           // jeda antar TP_GetCardSnr
            public int ACTIVE_TICK_MS = 200;   // frekuensi TP_M1Active (jarang biar blink pelan)
            public int REINIT_DELAY_MS = 40;

            // agar tap singkat masih ketangkap, boleh burst kecil:
            public int BURST_READS = 2;

            public bool BEEP_ON_READ = true;
            public bool DEVICE_BEEP = true;
            public bool PushOnScan = true;
            public int DebouncePushMs = 1500;

            public string LogLevel = "INFO";
            public string LogToFile = "";
            public string VendorDir = "";
            public string EnvPath = "";

            public static Config Load(string baseDir)
            {
                var c = new Config();
                c.EnvPath = Path.Combine(baseDir, "rfid.env");
                c.VendorDir = Path.Combine(baseDir, "vendor");

                // env
                c.LaravelApi = Env("LARAVEL_API", c.LaravelApi);
                c.BridgeToken = Env("BRIDGE_TOKEN", c.BridgeToken);
                c.Port = EnvInt("PORT", c.Port);
                c.LockTypes = Env("LOCK_TYPES", c.LockTypes);
                c.LockType = EnvInt("LOCK_TYPE", c.LockType);
                c.TIMEOUT_MS = EnvInt("TIMEOUT_MS", c.TIMEOUT_MS);
                c.POLL_MS = EnvInt("POLL_MS", c.POLL_MS);
                c.ACTIVE_TICK_MS = EnvInt("ACTIVE_TICK_MS", c.ACTIVE_TICK_MS);
                c.REINIT_DELAY_MS = EnvInt("REINIT_DELAY_MS", c.REINIT_DELAY_MS);
                c.BURST_READS = EnvInt("BURST_READS", c.BURST_READS);
                c.BEEP_ON_READ = EnvBool("BEEP_ON_READ", c.BEEP_ON_READ);
                c.DEVICE_BEEP = EnvBool("DEVICE_BEEP", c.DEVICE_BEEP);
                c.PushOnScan = EnvBool("PUSH_ON_SCAN", c.PushOnScan);
                c.DebouncePushMs = EnvInt("DEBOUNCE_MS", c.DebouncePushMs);
                c.LogLevel = Env("LOG_LEVEL", c.LogLevel);
                c.LogToFile = Env("LOG_TO_FILE", c.LogToFile);
                if (File.Exists(c.EnvPath))
                {
                    foreach (var raw0 in File.ReadAllLines(c.EnvPath))
                    {
                        var raw = (raw0 ?? "").Trim(); if (raw.Length == 0 || raw.StartsWith("#")) continue;
                        var idx = raw.IndexOf('='); if (idx <= 0) continue;
                        var key = raw.Substring(0, idx).Trim(); var val = raw.Substring(idx + 1).Trim();
                        var up = key.ToUpperInvariant();
                        if (up == "LARAVEL_API") c.LaravelApi = val;
                        else if (up == "BRIDGE_TOKEN") c.BridgeToken = val;
                        else if (up == "PORT") c.Port = ToInt(val, c.Port);
                        else if (up == "LOCK_TYPES") c.LockTypes = val;
                        else if (up == "LOCK_TYPE") c.LockType = ToInt(val, c.LockType);
                        else if (up == "TIMEOUT_MS") c.TIMEOUT_MS = ToInt(val, c.TIMEOUT_MS);
                        else if (up == "POLL_MS") c.POLL_MS = ToInt(val, c.POLL_MS);
                        else if (up == "ACTIVE_TICK_MS") c.ACTIVE_TICK_MS = ToInt(val, c.ACTIVE_TICK_MS);
                        else if (up == "REINIT_DELAY_MS") c.REINIT_DELAY_MS = ToInt(val, c.REINIT_DELAY_MS);
                        else if (up == "BURST_READS") c.BURST_READS = ToInt(val, c.BURST_READS);
                        else if (up == "BEEP_ON_READ") c.BEEP_ON_READ = ToBool(val, c.BEEP_ON_READ);
                        else if (up == "DEVICE_BEEP") c.DEVICE_BEEP = ToBool(val, c.DEVICE_BEEP);
                        else if (up == "PUSH_ON_SCAN") c.PushOnScan = ToBool(val, c.PushOnScan);
                        else if (up == "DEBOUNCE_MS") c.DebouncePushMs = ToInt(val, c.DebouncePushMs);
                        else if (up == "LOG_LEVEL") c.LogLevel = val;
                        else if (up == "LOG_TO_FILE") c.LogToFile = val;
                        else if (up == "VENDOR_DIR") c.VendorDir = val;
                    }
                }
                return c;
            }
            static string Env(string k, string defv) { var v = Environment.GetEnvironmentVariable(k); return string.IsNullOrEmpty(v) ? defv : v; }
            static int EnvInt(string k, int defv) { int v; var s = Environment.GetEnvironmentVariable(k); return int.TryParse(s, out v) ? v : defv; }
            static bool EnvBool(string k, bool defv) { var s = Environment.GetEnvironmentVariable(k); if (string.IsNullOrEmpty(s)) return defv; s = s.ToLowerInvariant(); return s == "1" || s == "true" || s == "yes" || s == "on"; }
            static int ToInt(string s, int defv) { int v; return int.TryParse(s, out v) ? v : defv; }
            static bool ToBool(string s, bool defv) { if (string.IsNullOrEmpty(s)) return defv; s = s.ToLowerInvariant(); return s == "1" || s == "true" || s == "yes" || s == "on"; }
        }

        enum Lvl { INFO = 1, DEBUG = 0 }
        static class Log
        {
            static object _lock = new object();
            static string _file = null;
            static Lvl _lvl = Lvl.INFO;
            static Stopwatch _sw = Stopwatch.StartNew();
            public static void Init(string filePath, string level) { _file = string.IsNullOrWhiteSpace(filePath) ? null : filePath; _lvl = (level ?? "INFO").ToUpperInvariant().StartsWith("D") ? Lvl.DEBUG : Lvl.INFO; }
            public static void Info(string msg) { Write(Lvl.INFO, msg); }
            public static void Debug(string msg) { if (_lvl == Lvl.DEBUG) Write(Lvl.DEBUG, msg); }
            static void Write(Lvl lvl, string msg)
            {
                var line = string.Format("[{0:000000} ms] [{1}] {2}", _sw.ElapsedMilliseconds, lvl == Lvl.DEBUG ? "DBG" : "INF", msg);
                lock (_lock) { if (_file == null) Console.WriteLine(line); else File.AppendAllText(_file, line + Environment.NewLine); }
            }
        }

        // ===== Shared =====
        static Config Cfg;
        static string LastPushedUid = null;
        static DateTime LastPushedAt = DateTime.MinValue;

        static void Main(string[] args)
        {
            var baseDir = AppDomain.CurrentDomain.BaseDirectory;
            Cfg = Config.Load(baseDir);
            if (!string.IsNullOrWhiteSpace(Cfg.LogToFile)) { try { File.WriteAllText(Cfg.LogToFile, ""); } catch { } }
            Log.Init(Cfg.LogToFile, Cfg.LogLevel);

            // SINGLE instance
            bool created;
            var mux = new Mutex(true, "Global\\HotelBomeRFIDBridge_" + Cfg.Port, out created);
            if (!created) { Console.WriteLine("[WARN] Another instance running. Exiting."); return; }

            // Vendor DLL path
            var vendor = string.IsNullOrWhiteSpace(Cfg.VendorDir) ? Path.Combine(baseDir, "vendor") : Cfg.VendorDir;
            try { SetDllDirectory(vendor); } catch { }

            Log.Info(string.Format("Bridge @127.0.0.1:{0} — idle; reader aktif hanya saat /scan. Timeout={1}ms, DLock-like poll={2}ms, activeTick={3}ms",
                Cfg.Port, Cfg.TIMEOUT_MS, Cfg.POLL_MS, Cfg.ACTIVE_TICK_MS));

            // HTTP only — no warmup, no engine -> LED tidak berkedip saat start
            var http = new HttpListener();
            http.Prefixes.Add("http://127.0.0.1:" + Cfg.Port + "/");
            http.Start();
            http.BeginGetContext(OnHttp, http);
            Log.Info("HTTP ready. Endpoint: /scan");
            Thread.Sleep(Timeout.Infinite);
        }

        static void OnHttp(IAsyncResult ar)
        {
            var http = (HttpListener)ar.AsyncState; HttpListenerContext ctx = null;
            try { ctx = http.EndGetContext(ar); }
            catch { return; }
            finally { try { http.BeginGetContext(OnHttp, http); } catch { } }

            var req = ctx.Request; var res = ctx.Response; res.AddHeader("Cache-Control", "no-store");
            if (!req.Url.AbsolutePath.Equals("/scan", StringComparison.OrdinalIgnoreCase))
            { WriteJson(res, 404, "{\"ok\":false,\"msg\":\"use /scan\"}"); return; }

            int timeout = Cfg.TIMEOUT_MS; int otm; var v = GetQueryParam(req.Url.Query, "timeout_ms");
            if (!string.IsNullOrEmpty(v) && int.TryParse(v, out otm) && otm > 0 && otm <= 60000) timeout = otm;

            var sw = Stopwatch.StartNew();
            bool ok; string uid; int rc;
            try
            {
                var result = DoOneScan(timeout);
                ok = result.Item1; uid = result.Item2; rc = result.Item3;
            }
            catch (Exception ex)
            {
                WriteJson(res, 500, "{\"ok\":false,\"err\":\"" + Safe(ex.Message) + "\"}");
                Log.Info("[ERR]/scan " + ex.Message);
                return;
            }

            WriteJson(res, 200, "{\"ok\":" + (ok ? "true" : "false") + ",\"rc\":" + rc + ",\"snr\":\"" + (uid ?? "") + "\"}");

            if (ok)
            {
                ThreadPool.QueueUserWorkItem(_ =>
                {
                    if (Cfg.BEEP_ON_READ) TryBeep();
                    if (Cfg.PushOnScan) TryPush(uid);
                });
            }

            sw.Stop();
            Log.Debug(string.Format("/scan ok={0} rc={1} uid={2} latency={3}ms", ok, rc, uid ?? "-", sw.ElapsedMilliseconds));
        }

        /// <summary>
        /// Lakukan satu sesi: SoftReset → wait rising-edge (no-card→card) → SoftReset
        /// Tanpa state global & tanpa polling di background.
        /// </summary>
        static Tuple<bool, string, int> DoOneScan(int timeoutMs)
        {
            int[] types = ParseLockTypes(); if (types.Length == 0) types = new int[] { Cfg.LockType };
            int lt = types[0];

            // === Reset sebelum baca (LED aktif hanya saat window ini) ===
            SoftReset(lt);

            var deadline = DateTime.UtcNow.AddMilliseconds(timeoutMs);
            var sb = new StringBuilder(128);
            bool prevPresent = false;
            string uidFound = null;

            DateTime lastActiveTick = DateTime.MinValue;

            while (DateTime.UtcNow < deadline)
            {
                // Active tick jarang agar blink lambat (DLock-like)
                if ((DateTime.UtcNow - lastActiveTick).TotalMilliseconds >= Cfg.ACTIVE_TICK_MS)
                {
                    try { var s2 = new StringBuilder(64); s2.Append('\0', 64); TP_M1Active(s2); } catch { }
                    lastActiveTick = DateTime.UtcNow;
                }

                for (int b = 0; b < Cfg.BURST_READS; b++)
                {
                    int rc; string snr = null;
                    try { sb = new StringBuilder(128); sb.Append('\0', 128); rc = TP_GetCardSnr(sb); }
                    catch { rc = int.MinValue; }

                    bool nowPresent = false;
                    if (rc > 0)
                    {
                        snr = (sb.ToString() ?? "").Trim('\0', ' ', '\r', '\n', '\t');
                        if (!string.IsNullOrEmpty(snr)) nowPresent = true;
                    }

                    // RISING EDGE strict di dalam window /scan
                    if (nowPresent && !prevPresent)
                    {
                        uidFound = snr;
                        SoftReset(lt); // reset langsung setelah sukses (re-init)
                        return Tuple.Create(true, uidFound, 1);
                    }

                    // rc khusus
                    if (rc == -4)
                    {
                        try { var s3 = new StringBuilder(64); s3.Append('\0', 64); TP_M1Active(s3); } catch { }
                    }
                    else if (rc == -9)
                    {
                        // mini reinit ringan
                        SafeConfig(lt);
                        Thread.Sleep(Cfg.REINIT_DELAY_MS);
                        try { var s4 = new StringBuilder(64); s4.Append('\0', 64); TP_M1Active(s4); } catch { }
                    }

                    prevPresent = nowPresent;
                }

                Thread.Sleep(Math.Max(1, Cfg.POLL_MS));
            }

            // === Timeout → reset lagi lalu keluar ===
            SoftReset(lt);
            return Tuple.Create(false, (string)null, 0);
        }

        // Soft reset ringan: config + 2x active tick kecil → cukup menyiapkan reader untuk single read
        static void SoftReset(int lockType)
        {
            try
            {
                SafeConfig(lockType);
                Thread.Sleep(8);
                try { var s1 = new StringBuilder(32); s1.Append('\0', 32); TP_M1Active(s1); } catch { }
                Thread.Sleep(8);
                try { var s2 = new StringBuilder(32); s2.Append('\0', 32); TP_M1Active(s2); } catch { }
                Thread.Sleep(Math.Max(20, Cfg.REINIT_DELAY_MS));
            }
            catch { }
        }

        static void SafeConfig(int t) { try { TP_Configuration(t); } catch { } }

        static void TryPush(string uid)
        {
            try
            {
                bool suppressed = false;
                if (LastPushedUid == uid && (DateTime.UtcNow - LastPushedAt).TotalMilliseconds < Cfg.DebouncePushMs)
                    suppressed = true;
                else { LastPushedUid = uid; LastPushedAt = DateTime.UtcNow; }

                if (suppressed) { Log.Debug("push-suppressed uid=" + uid); return; }

                string url = Cfg.LaravelApi.TrimEnd('/') + "/api/card-scan";
                string json = "{\"uid\":\"" + uid + "\"}";
                byte[] data = Encoding.UTF8.GetBytes(json);

                var req = (HttpWebRequest)WebRequest.Create(url);
                req.Method = "POST";
                req.ContentType = "application/json";
                req.Headers["x-bridge-token"] = Cfg.BridgeToken;
                req.Timeout = 3000;

                using (var s = req.GetRequestStream()) { s.Write(data, 0, data.Length); }
                using (var res = (HttpWebResponse)req.GetResponse())
                using (var rs = new StreamReader(res.GetResponseStream()))
                {
                    var body = rs.ReadToEnd();
                    Log.Debug("[POST] " + body);
                }
            }
            catch (Exception ex) { Log.Info("[POST-ERR] " + ex.Message); }
        }

        static void TryBeep()
        {
            try
            {
                if (Cfg.DEVICE_BEEP)
                {
                    try { TP_Beep_Optional(70); return; } catch { }
                    try { RC500Beep_Optional(1); return; } catch { }
                    try { DevBeep_Optional(70); return; } catch { }
                }
                Console.Beep(1200, 60);
            }
            catch { }
        }

        // ===== Helpers =====
        static int[] ParseLockTypes()
        {
            try
            {
                var s = (Cfg.LockTypes ?? "").Trim();
                if (s.Length == 0) return new int[] { Cfg.LockType };
                var parts = s.Split(new char[] { ',' }, StringSplitOptions.RemoveEmptyEntries);
                int[] arr = new int[parts.Length]; int n = 0;
                for (int i = 0; i < parts.Length; i++) { int v; if (int.TryParse(parts[i].Trim(), out v)) arr[n++] = v; }
                if (n == 0) return new int[] { Cfg.LockType };
                if (n != arr.Length) { var z = new int[n]; Array.Copy(arr, z, n); return z; }
                return arr;
            }
            catch { return new int[] { Cfg.LockType }; }
        }

        static void WriteJson(HttpListenerResponse res, int status, string body)
        {
            var buf = Encoding.UTF8.GetBytes(body ?? "{}");
            res.StatusCode = status;
            res.ContentType = "application/json; charset=utf-8";
            try { res.OutputStream.Write(buf, 0, buf.Length); } catch { }
            try { res.Close(); } catch { }
        }

        static string GetQueryParam(string query, string key)
        {
            if (string.IsNullOrEmpty(query)) return null;
            if (query.StartsWith("?")) query = query.Substring(1);
            var parts = query.Split('&');
            for (int i = 0; i < parts.Length; i++)
            {
                var kv = parts[i].Split('=');
                if (kv.Length >= 1)
                {
                    var k = Uri.UnescapeDataString(kv[0] ?? "");
                    if (string.Equals(k, key, StringComparison.OrdinalIgnoreCase))
                    {
                        var val = kv.Length >= 2 ? kv[1] : "";
                        return Uri.UnescapeDataString(val);
                    }
                }
            }
            return null;
        }

        static string Safe(string s) { return (s ?? "").Replace("\\", "\\\\").Replace("\"", "\\\""); }
    }
}
