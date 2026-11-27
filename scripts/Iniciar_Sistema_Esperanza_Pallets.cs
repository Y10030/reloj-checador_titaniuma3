using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net.Sockets;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;

class Launcher
{
    private static readonly List<Process> Children = new List<Process>();
    private static readonly object SyncLock = new object();
    private static string TunnelUrl = null;

    static void Main(string[] args)
    {
        Console.OutputEncoding = Encoding.UTF8;
        Console.Title = "Pallets La Esperanza - Planta Coacalco (Acceso 3 TITANIUM)";

        AppDomain.CurrentDomain.ProcessExit += (s, e) => StopAll();
        Console.CancelKeyPress += (s, e) =>
        {
            e.Cancel = true;
            StopAll();
            Environment.Exit(0);
        };

        string root = AppDomain.CurrentDomain.BaseDirectory.TrimEnd('\\', '/');
        string logsDir = Path.Combine(root, "logs");
        Directory.CreateDirectory(logsDir);

        var env = LoadEnv(Path.Combine(root, ".env"));
        int webPort = ParseInt(env, "WEB_PORT", 8080);
        int facialPort = ParseInt(env, "FACIAL_PORT", 8001);
        string fbHost = GetEnv(env, "TITANIUM_FB_HOST", "127.0.0.1");
        if (string.Equals(fbHost, "localhost", StringComparison.OrdinalIgnoreCase)) fbHost = "127.0.0.1";
        int fbPort = ParseInt(env, "TITANIUM_FB_PORT", 3050);

        string cfMode = GetEnv(env, "CLOUDFLARE_MODE", "").ToLowerInvariant();
        if (string.IsNullOrEmpty(cfMode))
        {
            bool quick = !string.Equals(GetEnv(env, "ENABLE_QUICK_TUNNEL", "true"), "false", StringComparison.OrdinalIgnoreCase);
            cfMode = quick ? "quick" : "external";
        }
        string cfConfig = GetEnv(env, "CLOUDFLARE_CONFIG", @"cloudflared\config.yml");
        string cfToken = GetEnv(env, "CLOUDFLARE_TUNNEL_TOKEN", "");
        string appBaseUrl = GetEnv(env, "APP_BASE_URL", "http://127.0.0.1:" + webPort).TrimEnd('/');

        PrintHeader();

        int totalSteps = cfMode == "external" ? 3 : 4;

        // 1. Verificar Firebird 2.5 (Acceso 3 TITANIUM)
        Console.Write("[1/" + totalSteps + "] Verificando Acceso 3 TITANIUM (" + fbHost + ":" + fbPort + ")... ");
        if (IsPortOpen(fbHost, fbPort, 1000))
            WriteColored("CONECTADO\n", ConsoleColor.Green);
        else
            WriteColored("NO DETECTADO (revise servicio Firebird 2.5)\n", ConsoleColor.Yellow);

        // 2. Microservicio Facial Python
        string pythonExe = FindExecutable(new[] {
            Path.Combine(root, @"facial-service\.venv\Scripts\python.exe"),
            @"C:\laragon\bin\python\python-3.10\python.exe",
            @"C:\Python311\python.exe",
            @"C:\Python310\python.exe",
            @"C:\Python312\python.exe"
        }, "python.exe");
        Console.Write("[2/" + totalSteps + "] Iniciando Microservicio Facial Python ONNX (:" + facialPort + ")... ");
        if (IsPortOpen("127.0.0.1", facialPort, 400))
        {
            WriteColored("YA ACTIVO\n", ConsoleColor.Cyan);
        }
        else if (pythonExe == null)
        {
            WriteColored("ERROR (no se encontro Python .venv)\n", ConsoleColor.Red);
        }
        else
        {
            StartLoggedProcess(
                pythonExe,
                "-m uvicorn app:app --host 127.0.0.1 --port " + facialPort,
                Path.Combine(root, "facial-service"),
                Path.Combine(logsDir, "facial.log"),
                null
            );
            if (WaitForPort("127.0.0.1", facialPort, 15000))
                WriteColored("LISTO\n", ConsoleColor.Green);
            else
                WriteColored("INICIANDO (revise logs\\facial.log)\n", ConsoleColor.Yellow);
        }

        // 3. Servidor Web PHP
        string phpExe = FindExecutable(new[] {
            @"C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe",
            @"C:\laragon\bin\php\php-8.2.20-Win32-vs16-x64\php.exe",
            @"C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe",
            @"C:\php\php.exe",
            @"C:\xampp\php\php.exe"
        }, "php.exe");
        Console.Write("[3/" + totalSteps + "] Iniciando Servidor Web PHP (127.0.0.1:" + webPort + ")... ");
        if (IsPortOpen("127.0.0.1", webPort, 400))
        {
            WriteColored("YA ACTIVO\n", ConsoleColor.Cyan);
        }
        else if (phpExe == null)
        {
            WriteColored("ERROR (no se encontro php.exe)\n", ConsoleColor.Red);
        }
        else
        {
            string pubDir = Path.Combine(root, @"backend\public");
            StartLoggedProcess(
                phpExe,
                "-S 127.0.0.1:" + webPort + " -t \"" + pubDir + "\"",
                root,
                Path.Combine(logsDir, "web.log"),
                null
            );
            if (WaitForPort("127.0.0.1", webPort, 8000))
                WriteColored("LISTO\n", ConsoleColor.Green);
            else
                WriteColored("ERROR AL INICIAR (revise logs\\web.log)\n", ConsoleColor.Red);
        }

        // 4. Cloudflare Tunnel (named_config | named_token | quick)
        if (cfMode != "external")
        {
            string cfExe = FindExecutable(new[] {
                Path.Combine(root, @"scripts\cloudflared.exe"),
                @"C:\Program Files (x86)\cloudflared\cloudflared.exe",
                @"C:\Program Files\cloudflared\cloudflared.exe"
            }, "cloudflared.exe");

            if (cfMode == "named_config")
            {
                string cfgFull = Path.IsPathRooted(cfConfig) ? cfConfig : Path.Combine(root, cfConfig);
                Console.Write("[4/4] Iniciando Cloudflare Tunnel fijo (" + appBaseUrl + ")... ");
                if (cfExe == null || !File.Exists(cfgFull))
                {
                    WriteColored("NO CONFIGURADO (revise " + cfgFull + ")\n", ConsoleColor.Yellow);
                }
                else
                {
                    bool connected = false;
                    StartLoggedProcess(
                        cfExe,
                        "tunnel --no-autoupdate --config \"" + cfgFull + "\" run",
                        root,
                        Path.Combine(logsDir, "tunnel.log"),
                        (line) =>
                        {
                            if (!string.IsNullOrEmpty(line) && line.IndexOf("Registered tunnel connection", StringComparison.OrdinalIgnoreCase) >= 0)
                                connected = true;
                        }
                    );
                    int waited = 0;
                    while (!connected && waited < 12000) { Thread.Sleep(250); waited += 250; }
                    TunnelUrl = appBaseUrl;
                    WriteColored(connected ? "ACTIVO\n" : "CONECTANDO (revise logs\\tunnel.log)\n", connected ? ConsoleColor.Green : ConsoleColor.Yellow);
                }
            }
            else if (cfMode == "named_token")
            {
                Console.Write("[4/4] Iniciando Cloudflare Tunnel con token (" + appBaseUrl + ")... ");
                if (cfExe == null || string.IsNullOrWhiteSpace(cfToken))
                {
                    WriteColored("NO CONFIGURADO (falta CLOUDFLARE_TUNNEL_TOKEN en .env)\n", ConsoleColor.Yellow);
                }
                else
                {
                    bool connected = false;
                    StartLoggedProcess(
                        cfExe,
                        "tunnel --no-autoupdate run --token " + cfToken,
                        root,
                        Path.Combine(logsDir, "tunnel.log"),
                        (line) =>
                        {
                            if (!string.IsNullOrEmpty(line) && line.IndexOf("Registered tunnel connection", StringComparison.OrdinalIgnoreCase) >= 0)
                                connected = true;
                        }
                    );
                    int waited = 0;
                    while (!connected && waited < 12000) { Thread.Sleep(250); waited += 250; }
                    TunnelUrl = appBaseUrl;
                    WriteColored(connected ? "ACTIVO\n" : "CONECTANDO (revise logs\\tunnel.log)\n", connected ? ConsoleColor.Green : ConsoleColor.Yellow);
                }
            }
            else
            {
                Console.Write("[4/4] Abriendo Cloudflare Tunnel HTTPS... ");
                if (cfExe == null)
                {
                    WriteColored("NO ENCONTRADO (solo acceso local http://127.0.0.1:" + webPort + ")\n", ConsoleColor.Yellow);
                }
                else
                {
                    Regex urlRegex = new Regex(@"https://[a-z0-9-]+\.trycloudflare\.com", RegexOptions.IgnoreCase);
                    StartLoggedProcess(
                        cfExe,
                        "tunnel --no-autoupdate --url http://127.0.0.1:" + webPort,
                        root,
                        Path.Combine(logsDir, "tunnel.log"),
                        (line) =>
                        {
                            if (TunnelUrl == null && !string.IsNullOrEmpty(line))
                            {
                                Match m = urlRegex.Match(line);
                                if (m.Success)
                                {
                                    TunnelUrl = m.Value;
                                    try { File.WriteAllText(Path.Combine(logsDir, "url_actual.txt"), TunnelUrl); } catch { }
                                }
                            }
                        }
                    );

                    int waited = 0;
                    while (TunnelUrl == null && waited < 14000)
                    {
                        Thread.Sleep(250);
                        waited += 250;
                    }
                    if (TunnelUrl != null)
                        WriteColored("ACTIVO\n", ConsoleColor.Green);
                    else
                        WriteColored("CONECTANDO (revise logs\\tunnel.log)\n", ConsoleColor.Yellow);
                }
            }
        }

        string publicUrl = TunnelUrl ?? appBaseUrl;

        Console.WriteLine();
        Console.WriteLine("======================================================================");
        WriteColored("  SISTEMA EN OPERACION - PALLETS LA ESPERANZA (PLANTA COACALCO)\n", ConsoleColor.Green);
        Console.WriteLine("======================================================================");
        Console.WriteLine("  Local Panel Admin : http://127.0.0.1:" + webPort + "/admin.php");
        Console.WriteLine("  Local Celular     : http://127.0.0.1:" + webPort + "/index.php");
        if (!string.IsNullOrEmpty(publicUrl) && publicUrl.StartsWith("https://", StringComparison.OrdinalIgnoreCase))
        {
            Console.WriteLine();
            WriteColored("  CELULARES (HTTPS) : " + publicUrl + "/index.php\n", ConsoleColor.Cyan);
            WriteColored("  ADMIN (HTTPS)     : " + publicUrl + "/admin.php\n", ConsoleColor.Cyan);
        }
        Console.WriteLine("======================================================================");
        Console.WriteLine("  Comandos: [A] Abrir Panel Admin | [C] Abrir Vista Celular | [Q] Apagar todo");
        Console.WriteLine("  (Mantenga esta ventana abierta mientras el sistema este en uso)");
        Console.WriteLine();

        while (true)
        {
            var key = Console.ReadKey(true);
            if (key.Key == ConsoleKey.A)
            {
                OpenUrl("http://127.0.0.1:" + webPort + "/admin.php");
            }
            else if (key.Key == ConsoleKey.C)
            {
                OpenUrl(publicUrl + "/index.php");
            }
            else if (key.Key == ConsoleKey.Q)
            {
                Console.WriteLine("Apagando servicios...");
                StopAll();
                break;
            }
        }
    }

    static Dictionary<string, string> LoadEnv(string path)
    {
        var map = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        if (!File.Exists(path)) return map;
        foreach (string raw in File.ReadAllLines(path))
        {
            string line = raw.Trim();
            if (line.Length == 0 || line.StartsWith("#")) continue;
            int idx = line.IndexOf('=');
            if (idx <= 0) continue;
            string k = line.Substring(0, idx).Trim();
            string v = line.Substring(idx + 1).Trim().Trim('"', '\'');
            map[k] = v;
        }
        return map;
    }

    static string GetEnv(Dictionary<string, string> env, string key, string fallback)
    {
        string v;
        return env.TryGetValue(key, out v) && !string.IsNullOrWhiteSpace(v) ? v : fallback;
    }

    static int ParseInt(Dictionary<string, string> env, string key, int fallback)
    {
        int n;
        return int.TryParse(GetEnv(env, key, ""), out n) && n > 0 ? n : fallback;
    }

    static void PrintHeader()
    {
        Console.Clear();
        Console.WriteLine("======================================================================");
        WriteColored("  PALLETS LA ESPERANZA · PLANTA COACALCO (ACCESO 3 TITANIUM)\n", ConsoleColor.Green);
        Console.WriteLine("======================================================================");
        Console.WriteLine();
    }

    static void WriteColored(string text, ConsoleColor color)
    {
        var prev = Console.ForegroundColor;
        Console.ForegroundColor = color;
        Console.Write(text);
        Console.ForegroundColor = prev;
    }

    static string FindExecutable(string[] candidates, string commandName)
    {
        foreach (string c in candidates)
        {
            if (File.Exists(c)) return c;
        }
        string pathEnv = Environment.GetEnvironmentVariable("PATH") ?? "";
        foreach (string dir in pathEnv.Split(Path.PathSeparator))
        {
            try
            {
                string full = Path.Combine(dir.Trim(), commandName);
                if (File.Exists(full)) return full;
            }
            catch { }
        }
        return null;
    }

    static bool IsPortOpen(string host, int port, int timeoutMs)
    {
        try
        {
            using (var client = new TcpClient())
            {
                var res = client.BeginConnect(host, port, null, null);
                bool ok = res.AsyncWaitHandle.WaitOne(timeoutMs);
                if (ok && client.Connected)
                {
                    client.EndConnect(res);
                    return true;
                }
            }
        }
        catch { }
        return false;
    }

    static bool WaitForPort(string host, int port, int maxMs)
    {
        int elapsed = 0;
        while (elapsed < maxMs)
        {
            if (IsPortOpen(host, port, 300)) return true;
            Thread.Sleep(350);
            elapsed += 350;
        }
        return false;
    }

    static void StartLoggedProcess(string fileName, string arguments, string workDir, string logPath, Action<string> onLine)
    {
        var psi = new ProcessStartInfo
        {
            FileName = fileName,
            Arguments = arguments,
            WorkingDirectory = workDir,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true
        };

        var p = new Process { StartInfo = psi, EnableRaisingEvents = true };
        var writer = new StreamWriter(logPath, false, Encoding.UTF8) { AutoFlush = true };

        DataReceivedEventHandler handler = (s, e) =>
        {
            if (e.Data == null) return;
            lock (writer)
            {
                try { writer.WriteLine(e.Data); } catch { }
            }
            if (onLine != null) onLine(e.Data);
        };

        p.OutputDataReceived += handler;
        p.ErrorDataReceived += handler;
        p.Start();
        p.BeginOutputReadLine();
        p.BeginErrorReadLine();

        lock (SyncLock)
        {
            Children.Add(p);
        }
    }

    static void OpenUrl(string url)
    {
        try
        {
            Process.Start(new ProcessStartInfo { FileName = url, UseShellExecute = true });
        }
        catch { }
    }

    static void StopAll()
    {
        lock (SyncLock)
        {
            foreach (var p in Children)
            {
                try
                {
                    if (!p.HasExited) p.Kill();
                }
                catch { }
            }
            Children.Clear();
        }
    }
}
