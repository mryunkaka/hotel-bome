// HotelBome RFID Bridge — v2.9.1 ARMED RISING-EDGE (C#5 / .NET 4.x)
// Idle total di luar /scan. Di dalam /scan:
//   SoftReset() → buang-cache awal → Wajib lihat ABSEN dulu → terima RISING-EDGE pertama → SoftReset().
// Timing meniru DLock: poll ~15ms, active tick jarang (~220ms) → LED biru blink lambat.
// Burst reads 3x/loop → tap singkat tetap ketangkap. Mini-reinit untuk rc -9.
//
// Build (x86):
//   C:\Windows\Microsoft.NET\Framework\v4.0.30319\csc.exe /platform:x86 /target:exe /out:rfid.exe Program.cs
// Jalankan:
//   set PATH=%CD%\vendor;%PATH% && .\rfid.exe
using System;
using System.IO;
using System.Net;
using System.Text;
using System.Threading;
using System.Runtime.InteropServices;
using System.Diagnostics;
using System.Collections.Generic;

namespace HotelBome.RFID
{
    internal class Program
    {
        [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
        static extern bool SetDllDirectory(string lpPathName);

        // === Vendor SDK (sesuai manual) ===
        [DllImport("LockSDK.dll", CallingConvention = CallingConvention.StdCall, CharSet = CharSet.Ansi)]
        static extern int TP_Configuration(int lock_type);
        [DllImport("LockSDK.dll", CallingConvention = CallingConvention.StdCall, CharSet = CharSet.Ansi)]
        static extern int TP_M1Active(StringBuilder card_snr);
        [DllImport("LockSDK.dll", CallingConvention = CallingConvention.StdCall, CharSet = CharSet.Ansi)]
        static extern int TP_GetCardSnr(StringBuilder card_snr);

        // Opsional bunyi (abaikan bila tidak ada di SDK)
        [DllImport("LockSDK.dll", EntryPoint = "TP_Beep", CallingConvention = CallingConvention.StdCall)]
        static extern int TP_Beep_Optional(int ms);
        [DllImport("RC500USB.dll", EntryPoint = "RC500Beep", CallingConvention = CallingConvention.StdCall)]
        static extern int RC500Beep_Optional(int times);
        [DllImport("PubFuns.dll", EntryPoint = "DevBeep", CallingConvention = CallingConvention.StdCall)]
        static extern int DevBeep_Optional(int ms);

        class Config
        {
            public string LaravelApi = "http://hotel-bome.test";
            public string BridgeToken = "hotelbome-bridge-2025";
            public int Port = 8200;
            public string LockTypes = "5";          // fallback daftar tipe
            public int LockType = 5;                // default
            public int TIMEOUT_MS = 8000;

            // DLock-like cadence
            public int POLL_MS = 70;                // jeda antar TP_GetCardSnr
            public int ACTIVE_TICK_MS = 800;        // frekuensi TP_M1Active (jarang → blink lambat)
            public int REINIT_DELAY_MS = 40;        // mini reinit jeda ringan
            public int BURST_READS = 1;             // burst untuk tap singkat
            public int START_DISCARD_MS = 150;      // buang cache awal setelah reset
            public int ABSENCE_ARM_MS = 250;        // harus lihat absen dulu sebelum armed

            public bool BEEP_ON_READ = true;
            public bool DEVICE_BEEP = true;
            public bool PushOnScan = true;
            public int DebouncePushMs = 1500;

            public string LogLevel = "INFO";
            public string LogToFile = "";
            public string VendorDir = "";
            public string EnvPath = "";

            // --- Helper untuk C#5: static method, bukan local function ---
            private static int ParseInt(string s, int defv)
            {
                int v; return int.TryParse(s, out v) ? v : defv;
            }
            private static bool ParseBool(string s, bool defv)
            {
                if (string.IsNullOrEmpty(s)) return defv;
                s = s.Trim().ToLowerInvariant();
                return (s == "1" || s == "true" || s == "yes" || s == "on");
            }

            public static Config Load(string baseDir)
            {
                var c = new Config();
                c.EnvPath = Path.Combine(baseDir, "rfid.env");
                c.VendorDir = Path.Combine(baseDir, "vendor");

                // Ambil dari Environment Variable bila ada
                Func<string, string> Env = (k) => Environment.GetEnvironmentVariable(k);
                string v;

                if ((v = Env("LARAVEL_API")) != null) c.LaravelApi = v;
                if ((v = Env("BRIDGE_TOKEN")) != null) c.BridgeToken = v;
                if ((v = Env("PORT")) != null) c.Port = ParseInt(v, c.Port);
                if ((v = Env("LOCK_TYPES")) != null) c.LockTypes = v;
                if ((v = Env("LOCK_TYPE")) != null) c.LockType = ParseInt(v, c.LockType);
                if ((v = Env("TIMEOUT_MS")) != null) c.TIMEOUT_MS = ParseInt(v, c.TIMEOUT_MS);
                if ((v = Env("POLL_MS")) != null) c.POLL_MS = ParseInt(v, c.POLL_MS);
                if ((v = Env("ACTIVE_TICK_MS")) != null) c.ACTIVE_TICK_MS = ParseInt(v, c.ACTIVE_TICK_MS);
                if ((v = Env("REINIT_DELAY_MS")) != null) c.REINIT_DELAY_MS = ParseInt(v, c.REINIT_DELAY_MS);
                if ((v = Env("BURST_READS")) != null) c.BURST_READS = ParseInt(v, c.BURST_READS);
                if ((v = Env("START_DISCARD_MS")) != null) c.START_DISCARD_MS = ParseInt(v, c.START_DISCARD_MS);
                if ((v = Env("ABSENCE_ARM_MS")) != null) c.ABSENCE_ARM_MS = ParseInt(v, c.ABSENCE_ARM_MS);
                if ((v = Env("BEEP_ON_READ")) != null) c.BEEP_ON_READ = ParseBool(v, c.BEEP_ON_READ);
                if ((v = Env("DEVICE_BEEP")) != null) c.DEVICE_BEEP = ParseBool(v, c.DEVICE_BEEP);
                if ((v = Env("PUSH_ON_SCAN")) != null) c.PushOnScan = ParseBool(v, c.PushOnScan);
                if ((v = Env("DEBOUNCE_MS")) != null) c.DebouncePushMs = ParseInt(v, c.DebouncePushMs);
                if ((v = Env("LOG_LEVEL")) != null) c.LogLevel = v;
                if ((v = Env("LOG_TO_FILE")) != null) c.LogToFile = v;
                if ((v = Env("VENDOR_DIR")) != null) c.VendorDir = v;

                // Override dari file rfid.env bila ada
                if (File.Exists(c.EnvPath))
                {
                    var lines = File.ReadAllLines(c.EnvPath);
                    for (int i = 0; i < lines.Length; i++)
                    {
                        var raw0 = lines[i] ?? "";
                        var raw = raw0.Trim();
                        if (raw.Length == 0 || raw.StartsWith("#")) continue;
                        var idx = raw.IndexOf('=');
                        if (idx <= 0) continue;
                        var key = raw.Substring(0, idx).Trim();
                        var val = raw.Substring(idx + 1).Trim();

                        var K = key.ToUpperInvariant();
                        if (K == "LARAVEL_API") c.LaravelApi = val;
                        else if (K == "BRIDGE_TOKEN") c.BridgeToken = val;
                        else if (K == "PORT") c.Port = ParseInt(val, c.Port);
                        else if (K == "LOCK_TYPES") c.LockTypes = val;
                        else if (K == "LOCK_TYPE") c.LockType = ParseInt(val, c.LockType);
                        else if (K == "TIMEOUT_MS") c.TIMEOUT_MS = ParseInt(val, c.TIMEOUT_MS);
                        else if (K == "POLL_MS") c.POLL_MS = ParseInt(val, c.POLL_MS);
                        else if (K == "ACTIVE_TICK_MS") c.ACTIVE_TICK_MS = ParseInt(val, c.ACTIVE_TICK_MS);
                        else if (K == "REINIT_DELAY_MS") c.REINIT_DELAY_MS = ParseInt(val, c.REINIT_DELAY_MS);
                        else if (K == "BURST_READS") c.BURST_READS = ParseInt(val, c.BURST_READS);
                        else if (K == "START_DISCARD_MS") c.START_DISCARD_MS = ParseInt(val, c.START_DISCARD_MS);
                        else if (K == "ABSENCE_ARM_MS") c.ABSENCE_ARM_MS = ParseInt(val, c.ABSENCE_ARM_MS);
                        else if (K == "BEEP_ON_READ") c.BEEP_ON_READ = ParseBool(val, c.BEEP_ON_READ);
                        else if (K == "DEVICE_BEEP") c.DEVICE_BEEP = ParseBool(val, c.DEVICE_BEEP);
                        else if (K == "PUSH_ON_SCAN") c.PushOnScan = ParseBool(val, c.PushOnScan);
                        else if (K == "DEBOUNCE_MS") c.DebouncePushMs = ParseInt(val, c.DebouncePushMs);
                        else if (K == "LOG_LEVEL") c.LogLevel = val;
                        else if (K == "LOG_TO_FILE") c.LogToFile = val;
                        else if (K == "VENDOR_DIR") c.VendorDir = val;
                    }
                }
                return c;
            }
        }

        enum Lvl { INFO = 1, DEBUG = 0 }
        static class Log
        {
            static object _lock = new object();
            static string _file = null;
            static Lvl _lvl = Lvl.INFO;
            static Stopwatch _sw = Stopwatch.StartNew();
            public static void Init(string filePath, string level) { _file = string.IsNullOrWhiteSpace(filePath) ? null : filePath; _lvl = (level ?? "INFO").ToUpper().StartsWith("D") ? Lvl.DEBUG : Lvl.INFO; }
            public static void Info(string msg) { Write(Lvl.INFO, msg); }
            public static void Debug(string msg) { if (_lvl == Lvl.DEBUG) Write(Lvl.DEBUG, msg); }
            static void Write(Lvl lvl, string msg)
            {
                var line = string.Format("[{0:000000} ms] [{1}] {2}", _sw.ElapsedMilliseconds, lvl == Lvl.DEBUG ? "DBG" : "INF", msg);
                lock (_lock) { if (_file == null) Console.WriteLine(line); else File.AppendAllText(_file, line + Environment.NewLine); }
            }
        }

        static Config Cfg;
        static string LastPushedUid = null;
        static DateTime LastPushedAt = DateTime.MinValue;

        static void Main(string[] args)
        {
            var baseDir = AppDomain.CurrentDomain.BaseDirectory;
            Cfg = Config.Load(baseDir);
            if (!string.IsNullOrWhiteSpace(Cfg.LogToFile)) { try { File.WriteAllText(Cfg.LogToFile, ""); } catch { } }
            Log.Init(Cfg.LogToFile, Cfg.LogLevel);

            // Single instance
            bool created;
            var mux = new Mutex(true, "Global\\HotelBomeRFIDBridge_" + Cfg.Port, out created);
            if (!created) { Console.WriteLine("[WARN] Already running. Exit."); return; }

            // Path DLL vendor
            var vendor = string.IsNullOrWhiteSpace(Cfg.VendorDir) ? Path.Combine(baseDir, "vendor") : Cfg.VendorDir;
            try { SetDllDirectory(vendor); } catch { }

            Log.Info(string.Format("Bridge @127.0.0.1:{0} - idle; reader aktif hanya saat /scan. Timeout={1}ms, poll={2}ms, activeTick={3}ms",
                Cfg.Port, Cfg.TIMEOUT_MS, Cfg.POLL_MS, Cfg.ACTIVE_TICK_MS));

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

            int timeout = Cfg.TIMEOUT_MS;
            var q = GetQueryParam(req.Url.Query, "timeout_ms");
            int otm; if (!string.IsNullOrEmpty(q) && int.TryParse(q, out otm) && otm > 0 && otm <= 60000) timeout = otm;

            bool ok; string uid; int rc;
            try { var t = DoOneScan(timeout); ok = t.Item1; uid = t.Item2; rc = t.Item3; }
            catch (Exception ex) { WriteJson(res, 500, "{\"ok\":false,\"err\":\"" + Safe(ex.Message) + "\"}"); Log.Info("[ERR]/scan " + ex.Message); return; }

            WriteJson(res, 200, "{\"ok\":" + (ok ? "true" : "false") + ",\"rc\":" + rc + ",\"snr\":\"" + (uid ?? "") + "\"}");

            if (ok)
            {
                ThreadPool.QueueUserWorkItem(_ =>
                {
                    if (Cfg.BEEP_ON_READ) TryBeep();
                    if (Cfg.PushOnScan) TryPush(uid);
                });
            }
        }

        /// Satu sesi: SoftReset → discard cache → ARMED by ABSENCE → RISING-EDGE → SoftReset
        static Tuple<bool, string, int> DoOneScan(int timeoutMs)
        {
            var types = ParseLockTypes(); if (types.Length == 0) types = new int[] { Cfg.LockType };
            int lt = types[0];

            SoftReset(lt);
            var deadline = DateTime.UtcNow.AddMilliseconds(timeoutMs);

            // Buang-cache awal sesaat (sebagian SDK mengulang SNR terakhir)
            var discardUntil = DateTime.UtcNow.AddMilliseconds(Cfg.START_DISCARD_MS);

            // "Armed by absence": wajib melihat tidak ada kartu dulu
            bool armed = false;
            var mustSeeAbsenceUntil = DateTime.UtcNow.AddMilliseconds(Cfg.ABSENCE_ARM_MS);

            var sb = new StringBuilder(128);
            DateTime lastActiveTick = DateTime.MinValue;
            bool prevPresent = false;

            while (DateTime.UtcNow < deadline)
            {
                // Aktifasi HANYA ketika device "absen" agar medan tidak diganggu saat kartu sedang hadir.
                // Ini yang bikin LED blink jadi lambat & stabil seperti DLock.
                if (!prevPresent && (DateTime.UtcNow - lastActiveTick).TotalMilliseconds >= Cfg.ACTIVE_TICK_MS)
                {
                    try
                    {
                        var s2 = new StringBuilder(64); s2.Append('\0', 64);
                        TP_M1Active(s2);
                    }
                    catch { }
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

                    // Mini recover
                    if (rc == -4) { try { var s3 = new StringBuilder(64); s3.Append('\0', 64); TP_M1Active(s3); } catch { } }
                    else if (rc == -9)
                    {
                        SafeConfig(lt);
                        Thread.Sleep(Cfg.REINIT_DELAY_MS);
                        try { var s4 = new StringBuilder(64); s4.Append('\0', 64); TP_M1Active(s4); } catch { }
                    }

                    // Discard-window: abaikan pembacaan selama START_DISCARD_MS
                    if (DateTime.UtcNow < discardUntil) { prevPresent = nowPresent; continue; }

                    // Arming by absence: sebelum armed, kita harus melihat "absen" minimal sekali
                    if (!armed)
                    {
                        if (!nowPresent) armed = true; // melihat kosong → siap
                        else if (DateTime.UtcNow > mustSeeAbsenceUntil)
                        {
                            // paksa sentak ringan agar medan turun-naik
                            try { var s5 = new StringBuilder(64); s5.Append('\0', 64); TP_M1Active(s5); } catch { }
                        }
                        prevPresent = nowPresent;
                        continue;
                    }

                    // RISING-EDGE murni di dalam window /scan
                    if (nowPresent && !prevPresent)
                    {
                        SoftReset(lt); // reset setelah sukses
                        return Tuple.Create(true, snr, 1);
                    }

                    prevPresent = nowPresent;
                }

                Thread.Sleep(Math.Max(1, Cfg.POLL_MS));
            }

            SoftReset(lt);
            return Tuple.Create(false, (string)null, 0);
        }

        // Soft reset ringan: config + dua active-tick kecil + jeda
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

        static int[] ParseLockTypes()
        {
            try
            {
                var s = (Cfg.LockTypes ?? "").Trim();
                if (s.Length == 0) return new int[] { Cfg.LockType };
                var parts = s.Split(new char[] { ',' }, StringSplitOptions.RemoveEmptyEntries);
                var list = new List<int>();
                for (int i = 0; i < parts.Length; i++)
                {
                    int v;
                    if (int.TryParse(parts[i].Trim(), out v)) list.Add(v);
                }
                if (list.Count == 0) return new int[] { Cfg.LockType };
                return list.ToArray();
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
            var parts = query.Split('&'); // <— perbaikan: Split (huruf S besar)
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
