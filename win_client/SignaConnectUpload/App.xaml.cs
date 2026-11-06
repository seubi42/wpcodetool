using System;
using System.IO;
using System.Linq;
using System.Windows;

namespace SignaConnectUpload
{
    /// <summary>
    /// SignaConnectUpload.exe [--delete] [chemin\du\fichier.pdf]
    ///   sans fichier : écran de connexion / réglages, avec « Choisir un PDF… »
    ///   avec fichier : envoi vers SignaConnect puis ouverture du navigateur sur le brouillon
    ///   --delete     : supprime le PDF après un envoi réussi (fichiers temporaires d'imprimante)
    /// </summary>
    public partial class App : Application
    {
        protected override void OnStartup(StartupEventArgs e)
        {
            base.OnStartup(e);

            var deleteAfter = e.Args.Any(arg => string.Equals(arg, "--delete", StringComparison.OrdinalIgnoreCase));
            var file = e.Args.FirstOrDefault(arg => !arg.StartsWith("--", StringComparison.Ordinal));

            if (file != null)
            {
                try
                {
                    file = Path.GetFullPath(file.Trim('"'));
                }
                catch (Exception)
                {
                    // Chemin invalide : MainWindow le signalera (fichier introuvable).
                }
            }

            var window = new MainWindow(ClientSettings.Load(), file, deleteAfter);
            MainWindow = window;
            window.Show();
        }
    }
}
