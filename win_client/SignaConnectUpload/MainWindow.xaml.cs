using System;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Media.Animation;
using Microsoft.Win32;

namespace SignaConnectUpload
{
    public partial class MainWindow : Window
    {
        private readonly ClientSettings _settings;
        private readonly bool _deleteAfter;
        private string _file;
        private string _appUrl;
        private bool _busy;

        public MainWindow(ClientSettings settings, string file, bool deleteAfter)
        {
            InitializeComponent();
            _settings = settings;
            _file = file;
            _deleteAfter = deleteAfter;
            Card.Opacity = 0;
            Loaded += async (sender, e) =>
            {
                Play("Appear");
                await StartAsync();
            };
        }

        private async Task StartAsync()
        {
            if (_file != null)
            {
                // Lancé par l'imprimante : la fenêtre doit passer devant l'application qui imprime.
                Topmost = true;
                Activate();
                Topmost = false;
            }

            if (!_settings.IsConnected)
            {
                ShowLogin(null);
                return;
            }

            if (_file != null)
            {
                await PrepareUploadAsync();
            }
            else
            {
                ShowHome();
            }
        }

        /* ------------------------------------------------------------ Écrans */

        private void ShowLogin(string message)
        {
            ShowPanel(LoginPanel, "Connexion", "Accédez à votre espace de signature.");
            UrlBox.Text = string.IsNullOrEmpty(_settings.ServerUrl) ? "https://" : _settings.ServerUrl;
            LoginBox.Text = _settings.Login ?? "";
            PasswordBox.Password = "";

            if (message != null)
            {
                SetError(message);
            }
            else if (_file != null)
            {
                SetStatus("Connectez-vous pour envoyer « " + Path.GetFileName(_file) + " ».");
            }

            var focus = string.IsNullOrEmpty(_settings.ServerUrl) ? (UIElement) UrlBox : string.IsNullOrEmpty(_settings.Login) ? (UIElement) LoginBox : PasswordBox;
            Dispatcher.BeginInvoke(new Action(() => focus.Focus()));
        }

        private void ShowHome()
        {
            var who = string.IsNullOrEmpty(_settings.UserName) ? "" : _settings.UserName + " · ";
            ShowPanel(HomePanel, "SignaConnect", who + HostOf(_settings.ServerUrl));
        }

        private async Task PrepareUploadAsync()
        {
            ShowPanel(UploadPanel, "Nouvel envoi", "Le document s'ouvrira dans votre navigateur pour placer les signatures.");
            FileName.Text = Path.GetFileName(_file);
            TeamPanel.Visibility = Visibility.Collapsed;

            if (!File.Exists(_file))
            {
                FileDetails.Text = _file;
                UploadButton.IsEnabled = false;
                SetError("Fichier introuvable.");
                return;
            }

            var info = new FileInfo(_file);
            FileDetails.Text = FormatSize(info.Length);

            Profile profile;
            SetBusy(true, "Connexion à SignaConnect…");

            try
            {
                profile = await new ApiClient(_settings.ServerUrl).MeAsync(_settings.Token);
            }
            catch (ApiException exception)
            {
                SetBusy(false, null);

                if (exception.IsUnauthorized)
                {
                    Disconnect();
                    ShowLogin(exception.Message);
                }
                else
                {
                    SetError(exception.Message);
                }

                return;
            }

            SetBusy(false, null);
            _appUrl = profile.AppUrl;
            _settings.UserName = profile.UserName;

            if (profile.MaxUploadMb > 0 && info.Length > profile.MaxUploadMb * 1024L * 1024L)
            {
                UploadButton.IsEnabled = false;
                SetError("Le PDF dépasse " + profile.MaxUploadMb + " Mo.");
                return;
            }

            if (profile.Teams.Count == 0)
            {
                UploadButton.IsEnabled = false;
                SetError("Aucune équipe ne vous permet d'envoyer des documents. Voyez avec l'administrateur de votre organisation.");
                return;
            }

            TeamBox.ItemsSource = profile.Teams;
            TeamBox.SelectedItem = profile.Teams.FirstOrDefault(team => team.Id == _settings.LastTeamId) ?? profile.Teams[0];

            if (profile.Teams.Count == 1)
            {
                // Une seule équipe : rien à choisir, on enchaîne.
                await UploadAsync();
                return;
            }

            TeamPanel.Visibility = Visibility.Visible;
            UploadButton.Focus();
        }

        /* ------------------------------------------------------------ Actions */

        private async void OnLogin(object sender, RoutedEventArgs e)
        {
            if (_busy)
            {
                return;
            }

            var url = ClientSettings.NormalizeUrl(UrlBox.Text);
            var login = LoginBox.Text.Trim();

            if (url.Length < 10 || login.Length == 0 || PasswordBox.Password.Length == 0)
            {
                SetError("Indiquez l'adresse du site, votre identifiant et votre mot de passe.");
                return;
            }

            SetBusy(true, "Connexion…");

            try
            {
                var profile = await new ApiClient(url).LoginAsync(login, PasswordBox.Password);
                _settings.ServerUrl = url;
                _settings.Login = login;
                _settings.Token = profile.Token;
                _settings.UserName = profile.UserName;
                _settings.Save();
                _appUrl = profile.AppUrl;
                PasswordBox.Password = "";
                SetBusy(false, null);

                if (_file != null)
                {
                    await PrepareUploadAsync();
                }
                else
                {
                    ShowHome();
                }
            }
            catch (Exception exception)
            {
                SetBusy(false, null);
                SetError(exception is ApiException ? exception.Message : "Connexion impossible : " + exception.Message);
            }
        }

        private async void OnUpload(object sender, RoutedEventArgs e)
        {
            await UploadAsync();
        }

        private async Task UploadAsync()
        {
            var team = TeamBox.SelectedItem as Team;

            if (_busy || team == null)
            {
                return;
            }

            SetBusy(true, "Envoi du PDF…");

            try
            {
                var result = await new ApiClient(_settings.ServerUrl).UploadAsync(_settings.Token, _file, team.Id);
                _settings.LastTeamId = team.Id;
                _settings.Save();

                if (_deleteAfter)
                {
                    TryDelete(_file);
                }

                // Coche verte, puis le navigateur prend le relais.
                SetBusy(false, null);
                UploadButton.Visibility = Visibility.Collapsed;
                TeamPanel.Visibility = Visibility.Collapsed;
                TitleText.Text = "C'est parti !";
                LeadText.Text = "La suite se passe dans votre navigateur.";
                Play("Done");
                await Task.Delay(500);
                OpenBrowser(result.OpenUrl);
                await Task.Delay(1300);
                Close();
            }
            catch (Exception exception)
            {
                SetBusy(false, null);
                var api = exception as ApiException;

                if (api != null && api.IsUnauthorized)
                {
                    Disconnect();
                    ShowLogin(api.Message);
                }
                else
                {
                    SetError(api != null ? api.Message : "Envoi impossible : " + exception.Message);
                }
            }
        }

        private async void OnPick(object sender, MouseButtonEventArgs e)
        {
            var dialog = new OpenFileDialog { Filter = "Documents PDF (*.pdf)|*.pdf", Title = "Choisir un PDF à envoyer" };

            if (dialog.ShowDialog(this) == true)
            {
                _file = dialog.FileName;
                await PrepareUploadAsync();
            }
        }

        private void OnDragOver(object sender, DragEventArgs e)
        {
            var accepted = !_busy && _settings.IsConnected && DroppedPdf(e) != null;
            e.Effects = accepted ? DragDropEffects.Copy : DragDropEffects.None;
            DropBorder.Stroke = (Brush) FindResource(accepted ? "Brand" : "InputLine");
            e.Handled = true;
        }

        private void OnDragLeave(object sender, DragEventArgs e)
        {
            DropBorder.Stroke = (Brush) FindResource("InputLine");
        }

        private async void OnDrop(object sender, DragEventArgs e)
        {
            DropBorder.Stroke = (Brush) FindResource("InputLine");
            var file = DroppedPdf(e);

            if (!_busy && _settings.IsConnected && file != null)
            {
                _file = file;
                await PrepareUploadAsync();
            }
        }

        private void OnOpenApp(object sender, RoutedEventArgs e)
        {
            OpenBrowser(string.IsNullOrEmpty(_appUrl) ? _settings.ServerUrl : _appUrl);
        }

        private async void OnLogout(object sender, RoutedEventArgs e)
        {
            try
            {
                await new ApiClient(_settings.ServerUrl).LogoutAsync(_settings.Token);
            }
            catch (Exception)
            {
                // Déjà révoqué ou site injoignable : on oublie quand même le jeton du poste.
            }

            Disconnect();
            ShowLogin(null);
        }

        private void OnCancel(object sender, RoutedEventArgs e)
        {
            Close();
        }

        private void OnKeyDown(object sender, KeyEventArgs e)
        {
            if (e.Key == Key.Escape)
            {
                Close();
            }
        }

        /// <summary>Fenêtre sans barre de titre : on la déplace en tirant la carte.</summary>
        private void OnDragWindow(object sender, MouseButtonEventArgs e)
        {
            if (e.ButtonState == MouseButtonState.Pressed && !DropZone.IsMouseOver)
            {
                DragMove();
            }
        }

        /* ------------------------------------------------------------ Outils */

        private void ShowPanel(UIElement panel, string title, string lead)
        {
            LoginPanel.Visibility = panel == LoginPanel ? Visibility.Visible : Visibility.Collapsed;
            HomePanel.Visibility = panel == HomePanel ? Visibility.Visible : Visibility.Collapsed;
            UploadPanel.Visibility = panel == UploadPanel ? Visibility.Visible : Visibility.Collapsed;
            UploadButton.Visibility = Visibility.Visible;
            UploadButton.IsEnabled = true;
            TitleText.Text = title;
            LeadText.Text = lead;
            SetStatus(null);
        }

        private void SetBusy(bool busy, string message)
        {
            _busy = busy;
            LoginButton.IsEnabled = !busy;
            TeamBox.IsEnabled = !busy;
            UploadButton.Visibility = busy ? Visibility.Collapsed : Visibility.Visible;
            Bar.Visibility = busy && UploadPanel.Visibility == Visibility.Visible ? Visibility.Visible : Visibility.Collapsed;

            if (busy)
            {
                Play("Drawing");
                Play("Pulse");
                Play("BarMove");
                SetStatus(message);
            }
            else
            {
                Stop("Drawing");
                Stop("Pulse");
                Stop("BarMove");
            }
        }

        private void SetStatus(string message)
        {
            Status.Text = message ?? "";
            Status.Visibility = string.IsNullOrEmpty(message) ? Visibility.Collapsed : Visibility.Visible;
            Status.Foreground = (Brush) FindResource("Muted");
        }

        private void SetError(string message)
        {
            SetStatus(message);
            Status.Foreground = (Brush) FindResource("Danger");
            Play("Shake");
        }

        private void Play(string key)
        {
            ((Storyboard) FindResource(key)).Begin(this, true);
        }

        private void Stop(string key)
        {
            ((Storyboard) FindResource(key)).Stop(this);
        }

        private void Disconnect()
        {
            _settings.Token = null;
            _settings.Save();
        }

        private static string HostOf(string url)
        {
            Uri uri;
            return Uri.TryCreate(url ?? "", UriKind.Absolute, out uri) ? uri.Host : url;
        }

        private static string FormatSize(long bytes)
        {
            return bytes < 1024 * 1024 ? Math.Max(1, bytes / 1024) + " Ko" : (bytes / 1024.0 / 1024.0).ToString("0.0") + " Mo";
        }

        private static string DroppedPdf(DragEventArgs e)
        {
            var files = e.Data.GetData(DataFormats.FileDrop) as string[];
            return files != null && files.Length == 1 && files[0].EndsWith(".pdf", StringComparison.OrdinalIgnoreCase) ? files[0] : null;
        }

        private static void OpenBrowser(string url)
        {
            if (!string.IsNullOrEmpty(url) && (url.StartsWith("https://", StringComparison.OrdinalIgnoreCase) || url.StartsWith("http://", StringComparison.OrdinalIgnoreCase)))
            {
                Process.Start(new ProcessStartInfo(url) { UseShellExecute = true });
            }
        }

        private static void TryDelete(string path)
        {
            try
            {
                File.Delete(path);
            }
            catch (Exception)
            {
                // Fichier encore verrouillé par l'imprimante : tant pis, il reste dans son dossier temporaire.
            }
        }
    }
}
