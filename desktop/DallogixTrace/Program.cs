using System;
using System.Drawing;
using Microsoft.Web.WebView2.WinForms;
using Microsoft.Web.WebView2.Core;
using System.Diagnostics;
using System.IO;
using System.Net.Http;
using System.Text;
using System.Threading.Tasks;
using System.Windows.Forms;

namespace DallogixTrace;

internal static class Program
{
    [STAThread]
    static void Main()
    {
        ApplicationConfiguration.Initialize();
        Application.Run(new TraceForm());
    }
}

internal sealed class TraceForm : Form
{
    private const string TraceUrl = "http://127.0.0.1:8080";
    private readonly WebView2 web = new() { Dock = DockStyle.Fill };
    private readonly Panel loadingPanel = new();
    private readonly Label loadingStatus = new();
    private readonly ProgressBar loadingProgress = new();
    private bool webRecoveryInProgress;
    private int webRecoveryAttempts;
    private DateTime lastWebRecoveryAttempt = DateTime.MinValue;

    public TraceForm()
    {
        Text = "Dallogix Trace";
        WindowState = FormWindowState.Maximized;
        FormBorderStyle = FormBorderStyle.None;
        BackColor = Color.FromArgb(9, 27, 42);
        BuildLoadingPanel();
        web.Visible = false;
        Controls.Add(web);
        Controls.Add(loadingPanel);
        Shown += async (_, _) => await StartAsync();
    }

    private void BuildLoadingPanel()
    {
        loadingPanel.Dock = DockStyle.Fill;
        loadingPanel.BackColor = Color.FromArgb(9, 27, 42);

        var content = new TableLayoutPanel
        {
            Anchor = AnchorStyles.None,
            AutoSize = true,
            BackColor = Color.Transparent,
            ColumnCount = 1,
            RowCount = 4,
            Padding = new Padding(28),
        };
        content.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 560));
        content.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        content.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        content.RowStyles.Add(new RowStyle(SizeType.Absolute, 18));
        content.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        var brand = new Label
        {
            AutoSize = true,
            Dock = DockStyle.Fill,
            Font = new Font("Segoe UI", 27, FontStyle.Bold),
            ForeColor = Color.White,
            Text = "TRACE PLATFORM",
            TextAlign = ContentAlignment.MiddleCenter,
        };
        content.Controls.Add(brand, 0, 0);

        var title = new Label
        {
            AutoSize = true,
            Dock = DockStyle.Fill,
            Font = new Font("Segoe UI", 18, FontStyle.Regular),
            ForeColor = Color.FromArgb(189, 218, 232),
            Text = "Iniciando o Dallogix Trace",
            TextAlign = ContentAlignment.MiddleCenter,
            Padding = new Padding(0, 8, 0, 0),
        };
        content.Controls.Add(title, 0, 1);

        loadingProgress.Dock = DockStyle.Fill;
        loadingProgress.Style = ProgressBarStyle.Marquee;
        loadingProgress.MarqueeAnimationSpeed = 28;
        loadingProgress.ForeColor = Color.FromArgb(37, 158, 206);
        content.Controls.Add(loadingProgress, 0, 2);

        loadingStatus.AutoSize = true;
        loadingStatus.Dock = DockStyle.Fill;
        loadingStatus.Font = new Font("Segoe UI", 11, FontStyle.Regular);
        loadingStatus.ForeColor = Color.FromArgb(189, 218, 232);
        loadingStatus.Text = "Preparando os serviços locais...";
        loadingStatus.TextAlign = ContentAlignment.MiddleCenter;
        content.Controls.Add(loadingStatus, 0, 3);

        loadingPanel.Controls.Add(content);
        loadingPanel.Resize += (_, _) =>
        {
            content.Left = Math.Max(0, (loadingPanel.ClientSize.Width - content.Width) / 2);
            content.Top = Math.Max(0, (loadingPanel.ClientSize.Height - content.Height) / 2);
        };
    }

    private void SetLoadingStatus(string status)
    {
        loadingStatus.Text = status;
        loadingStatus.Refresh();
    }

    private async Task StartAsync()
    {
        var root = AppContext.BaseDirectory;
        var composeProjectDirectory = root.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        var compose = Path.Combine(root, "docker-compose.yml");
        if (!File.Exists(compose)) { ShowFailure("Instalação incompleta: docker-compose.yml não encontrado."); return; }
        var envFile = Path.Combine(root, ".env");
        if (!File.Exists(envFile)) { ShowFailure("Arquivo de configuração .env não encontrado. Execute novamente o instalador do Trace."); return; }
        try
        {
            var missingEnvKeys = FindMissingRequiredEnvKeys(envFile);
            if (missingEnvKeys.Length > 0)
            {
                ShowFailure($"A configuração local está incompleta: {string.Join(", ", missingEnvKeys)}. Execute novamente o instalador do Trace para concluir a configuração.");
                return;
            }
            SetLoadingStatus("Localizando o Docker Desktop...");
            var docker = FindDockerCli();
            SetLoadingStatus("Iniciando o mecanismo Linux do Docker...");
            await EnsureDockerEngineAsync(docker, root);
            SetLoadingStatus("Iniciando os serviços do Trace...");
            var composeResult = await RunProcessAsync(
                docker,
                $"compose --project-directory \"{composeProjectDirectory}\" --env-file \"{envFile}\" --profile industrial up -d",
                root,
                TimeSpan.FromMinutes(2)
            );
            if (composeResult.ExitCode != 0)
            {
                ShowFailure($"Não foi possível iniciar os serviços do Trace. {composeResult.Error.Trim()}");
                return;
            }
            SetLoadingStatus("Aguardando a API local e o banco de dados...");
            using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(3) };
            var activationError = "nenhuma resposta da API";
            for (var attempt = 0; attempt < 30; attempt++)
            {
                try
                {
                    using var response = await client.GetAsync($"{TraceUrl}/api/ativacao_local.php");
                    if (response.IsSuccessStatusCode) break;
                    activationError = $"HTTP {(int)response.StatusCode} ({response.StatusCode})";
                }
                catch (Exception error) { activationError = error.Message; }
                await Task.Delay(2000);
                if (attempt == 29) { ShowFailure($"A API de ativação local não ficou pronta. Confira as migrations e os logs do PHP/MySQL. Último erro: {activationError}"); return; }
            }
            SetLoadingStatus("Carregando a interface do Trace...");
            var webViewOptions = new CoreWebView2EnvironmentOptions(
                "--disable-smooth-scrolling --disable-features=ElasticOverscroll"
            );
            var webViewEnvironment = await CoreWebView2Environment.CreateAsync(
                browserExecutableFolder: null,
                userDataFolder: null,
                options: webViewOptions
            );
            await web.EnsureCoreWebView2Async(webViewEnvironment);
            web.CoreWebView2.Settings.AreDevToolsEnabled = false;
            web.CoreWebView2.Settings.AreDefaultContextMenusEnabled = false;
            web.CoreWebView2.Settings.AreBrowserAcceleratorKeysEnabled = false;
            web.CoreWebView2.Settings.IsZoomControlEnabled = false;
            web.CoreWebView2.Settings.IsPinchZoomEnabled = false;
            web.CoreWebView2.Settings.IsSwipeNavigationEnabled = false;
            web.CoreWebView2.ProcessFailed += RecoverWebViewAfterRendererFailure;
            web.BackColor = Color.FromArgb(9, 27, 42);
            SetLoadingStatus("Abrindo a interface local...");
            if (!await NavigateToTraceAsync(TimeSpan.FromSeconds(15)))
            {
                ShowFailure("A interface local não respondeu em 15 segundos ou foi recusada pelo WebView2. Confira o estado do nginx e do PHP.");
                return;
            }
            web.CoreWebView2.NavigationCompleted += RecoverWebViewAfterNavigationFailure;
            web.Visible = true;
            loadingPanel.Visible = false;
            web.BringToFront();
        }
        catch (Exception error) { ShowFailure($"Não foi possível iniciar o Trace. {error.Message}"); }
    }

    private async Task<bool> NavigateToTraceAsync(TimeSpan timeout)
    {
        var navigation = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        EventHandler<CoreWebView2NavigationCompletedEventArgs>? navigationCompleted = null;
        navigationCompleted = (_, args) => navigation.TrySetResult(args.IsSuccess);
        web.CoreWebView2.NavigationCompleted += navigationCompleted;
        try
        {
            web.CoreWebView2.Navigate(TraceUrl);
            var completed = await Task.WhenAny(navigation.Task, Task.Delay(timeout));
            return completed == navigation.Task && await navigation.Task;
        }
        finally
        {
            if (web.CoreWebView2 is not null)
            {
                web.CoreWebView2.NavigationCompleted -= navigationCompleted;
            }
        }
    }

    private async void RecoverWebViewAfterNavigationFailure(
        object? sender,
        CoreWebView2NavigationCompletedEventArgs args
    )
    {
        if (args.IsSuccess ||
            args.WebErrorStatus == CoreWebView2WebErrorStatus.OperationCanceled ||
            webRecoveryInProgress)
        {
            return;
        }

        await RecoverWebViewAsync("A navegação da interface falhou. Tentando restaurar a tela...");
    }

    private async void RecoverWebViewAfterRendererFailure(
        object? sender,
        CoreWebView2ProcessFailedEventArgs args
    )
    {
        if (args.ProcessFailedKind is not (
            CoreWebView2ProcessFailedKind.RenderProcessExited or
            CoreWebView2ProcessFailedKind.RenderProcessUnresponsive
        ) || webRecoveryInProgress)
        {
            return;
        }

        await RecoverWebViewAsync("A interface parou de responder. Recuperando a tela...");
    }

    private async Task RecoverWebViewAsync(string statusMessage)
    {
        if (webRecoveryInProgress) return;
        var now = DateTime.UtcNow;
        if (now - lastWebRecoveryAttempt > TimeSpan.FromMinutes(5))
        {
            webRecoveryAttempts = 0;
        }
        lastWebRecoveryAttempt = now;
        if (webRecoveryAttempts >= 3)
        {
            ShowFailure("A interface parou de responder repetidamente. Feche e abra o Trace e verifique os logs do PC industrial.", "A interface do Trace parou");
            return;
        }

        webRecoveryInProgress = true;
        webRecoveryAttempts += 1;
        web.Visible = false;
        loadingPanel.Visible = true;
        loadingPanel.BringToFront();
        SetLoadingStatus(statusMessage);
        try
        {
            await Task.Delay(TimeSpan.FromSeconds(1));
            if (IsDisposed || web.IsDisposed || web.CoreWebView2 is null)
            {
                return;
            }
            if (!await NavigateToTraceAsync(TimeSpan.FromSeconds(15)))
            {
                throw new TimeoutException("A tela não respondeu durante a recuperação.");
            }
            web.Visible = true;
            loadingPanel.Visible = false;
            web.BringToFront();
        }
        catch (Exception error)
        {
            ShowFailure($"A interface local não conseguiu se recuperar. {error.Message}", "A interface do Trace parou");
        }
        finally
        {
            webRecoveryInProgress = false;
        }
    }

    private static string[] FindMissingRequiredEnvKeys(string envFile)
    {
        var values = new System.Collections.Generic.Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var rawLine in File.ReadLines(envFile))
        {
            var line = rawLine.Trim();
            if (line.Length == 0 || line.StartsWith('#')) continue;
            var separator = line.IndexOf('=');
            if (separator <= 0) continue;

            var key = line[..separator].Trim();
            var value = line[(separator + 1)..].Trim();
            if (value.Length >= 2 &&
                ((value[0] == '"' && value[^1] == '"') || (value[0] == '\'' && value[^1] == '\'')))
            {
                value = value[1..^1].Trim();
            }
            values[key] = value;
        }

        var requiredKeys = new[] { "MYSQL_PASSWORD", "MYSQL_ROOT_PASSWORD", "TRACE_DEVICE_TOKEN", "CAMERA_DEVICE_TOKEN" };
        var missing = new System.Collections.Generic.List<string>();
        foreach (var key in requiredKeys)
        {
            if (!values.TryGetValue(key, out var value) || string.IsNullOrWhiteSpace(value)) missing.Add(key);
        }
        return missing.ToArray();
    }

    private static string FindDockerCli()
    {
        var candidates = new[]
        {
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "Docker", "Docker", "resources", "bin", "docker.exe"),
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Programs", "DockerDesktop", "resources", "bin", "docker.exe"),
            "docker.exe",
        };
        foreach (var candidate in candidates)
        {
            if (candidate.Equals("docker.exe", StringComparison.OrdinalIgnoreCase) || File.Exists(candidate)) return candidate;
        }
        throw new FileNotFoundException("Docker Desktop não foi encontrado.");
    }

    private static async Task EnsureDockerEngineAsync(string docker, string workingDirectory)
    {
        var check = await RunProcessAsync(
            docker,
            "info --format \"{{.OSType}}\"",
            workingDirectory,
            TimeSpan.FromSeconds(6)
        );
        if (check.ExitCode == 0 && check.Output.Trim().Equals("linux", StringComparison.OrdinalIgnoreCase)) return;

        var desktopCandidates = new[]
        {
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "Docker", "Docker", "Docker Desktop.exe"),
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Programs", "DockerDesktop", "Docker Desktop.exe"),
        };
        var desktop = Array.Find(desktopCandidates, File.Exists);
        if (desktop != null) Process.Start(new ProcessStartInfo(desktop) { UseShellExecute = true });

        for (var attempt = 0; attempt < 60; attempt++)
        {
            await Task.Delay(TimeSpan.FromSeconds(2));
            check = await RunProcessAsync(
                docker,
                "info --format \"{{.OSType}}\"",
                workingDirectory,
                TimeSpan.FromSeconds(6)
            );
            if (check.ExitCode == 0 && check.Output.Trim().Equals("linux", StringComparison.OrdinalIgnoreCase)) return;
        }
        throw new InvalidOperationException("O Docker Desktop não iniciou o mecanismo Linux a tempo.");
    }

    private static async Task<(int ExitCode, string Output, string Error)> RunProcessAsync(
        string fileName,
        string arguments,
        string workingDirectory,
        TimeSpan? timeout = null
    )
    {
        using var process = new Process
        {
            StartInfo = new ProcessStartInfo
            {
                FileName = fileName,
                Arguments = arguments,
                WorkingDirectory = workingDirectory,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                StandardOutputEncoding = Encoding.UTF8,
                StandardErrorEncoding = Encoding.UTF8,
            },
        };
        process.Start();
        var output = process.StandardOutput.ReadToEndAsync();
        var error = process.StandardError.ReadToEndAsync();
        var waitForExit = process.WaitForExitAsync();
        if (timeout.HasValue && await Task.WhenAny(waitForExit, Task.Delay(timeout.Value)) != waitForExit)
        {
            try { process.Kill(entireProcessTree: true); } catch { }
            await waitForExit;
            return (-1, await output, $"Tempo esgotado ao executar {fileName} {arguments}.");
        }
        await waitForExit;
        return (process.ExitCode, await output, await error);
    }

    private void ShowFailure(string message, string titleText = "Não foi possível iniciar o Trace")
    {
        Controls.Clear();
        var panel = new Panel { Dock = DockStyle.Fill, BackColor = Color.FromArgb(9, 27, 42), Padding = new Padding(48) };
        var content = new TableLayoutPanel
        {
            Anchor = AnchorStyles.None,
            AutoSize = true,
            ColumnCount = 1,
            RowCount = 3,
            BackColor = Color.Transparent,
        };
        content.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 760));
        var title = new Label
        {
            AutoSize = true,
            Dock = DockStyle.Fill,
            Font = new Font("Segoe UI", 24, FontStyle.Bold),
            ForeColor = Color.White,
            Text = titleText,
            TextAlign = ContentAlignment.MiddleCenter,
        };
        var detail = new Label
        {
            AutoSize = true,
            Dock = DockStyle.Fill,
            Font = new Font("Segoe UI", 12),
            ForeColor = Color.FromArgb(189, 218, 232),
            Text = message,
            TextAlign = ContentAlignment.MiddleCenter,
            Padding = new Padding(0, 18, 0, 18),
        };
        var close = new Button
        {
            AutoSize = true,
            Anchor = AnchorStyles.None,
            Font = new Font("Segoe UI", 11, FontStyle.Bold),
            Text = "Fechar",
            Padding = new Padding(18, 8, 18, 8),
        };
        close.Click += (_, _) => Close();
        content.Controls.Add(title, 0, 0);
        content.Controls.Add(detail, 0, 1);
        content.Controls.Add(close, 0, 2);
        panel.Controls.Add(content);
        panel.Resize += (_, _) =>
        {
            content.Left = Math.Max(0, (panel.ClientSize.Width - content.Width) / 2);
            content.Top = Math.Max(0, (panel.ClientSize.Height - content.Height) / 2);
        };
        Controls.Add(panel);
    }
}
