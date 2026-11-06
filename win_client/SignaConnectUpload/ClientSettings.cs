using System;
using System.Collections.Generic;
using System.IO;
using System.Security.Cryptography;
using System.Text;
using System.Web.Script.Serialization;

namespace SignaConnectUpload
{
    /// <summary>
    /// Réglages du poste, propres à l'utilisateur Windows : %APPDATA%\SignaConnect\client.json
    /// (repris de %APPDATA%\SignConnect\client.json s'il n'existe pas encore).
    /// Le jeton est chiffré par Windows (DPAPI, portée utilisateur) : il n'est lisible que par
    /// ce compte Windows sur ce poste. Le mot de passe n'est jamais enregistré.
    /// </summary>
    public sealed class ClientSettings
    {
        // Valeur d'origine gardée telle quelle : la changer rendrait illisibles les jetons déjà enregistrés.
        private static readonly byte[] Entropy = Encoding.UTF8.GetBytes("SignConnectUpload/v1");

        public string ServerUrl { get; set; }
        public string Login { get; set; }
        public string UserName { get; set; }
        public int LastTeamId { get; set; }

        /// <summary>Jeton en clair, en mémoire seulement.</summary>
        public string Token { get; set; }

        public bool IsConnected
        {
            get { return !string.IsNullOrEmpty(ServerUrl) && !string.IsNullOrEmpty(Token); }
        }

        public static string FolderPath
        {
            get { return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "SignaConnect"); }
        }

        /// <summary>Réglages de l'ancien client SignConnect, lus une seule fois pour la reprise.</summary>
        private static string LegacyFilePath
        {
            get { return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "SignConnect", "client.json"); }
        }

        private static string FilePath
        {
            get { return Path.Combine(FolderPath, "client.json"); }
        }

        public static ClientSettings Load()
        {
            var settings = new ClientSettings();

            try
            {
                var path = File.Exists(FilePath) ? FilePath : LegacyFilePath;

                if (!File.Exists(path))
                {
                    return settings;
                }

                var data = new JavaScriptSerializer().Deserialize<Dictionary<string, object>>(File.ReadAllText(path, Encoding.UTF8));
                settings.ServerUrl = Read(data, "server_url");
                settings.Login = Read(data, "login");
                settings.UserName = Read(data, "user_name");
                int team;
                settings.LastTeamId = int.TryParse(Read(data, "last_team_id"), out team) ? team : 0;
                settings.Token = Unprotect(Read(data, "token"));
            }
            catch (Exception)
            {
                // Fichier abîmé ou jeton d'un autre compte Windows : on repart de zéro.
                settings.Token = null;
            }

            return settings;
        }

        public void Save()
        {
            Directory.CreateDirectory(FolderPath);
            var data = new Dictionary<string, object>
            {
                { "server_url", ServerUrl ?? "" },
                { "login", Login ?? "" },
                { "user_name", UserName ?? "" },
                { "last_team_id", LastTeamId },
                { "token", Protect(Token) },
            };
            File.WriteAllText(FilePath, new JavaScriptSerializer().Serialize(data), new UTF8Encoding(false));
        }

        /// <summary>« monsite.fr » → « https://monsite.fr », sans barre finale.</summary>
        public static string NormalizeUrl(string url)
        {
            url = (url ?? "").Trim();

            if (url.Length == 0)
            {
                return "";
            }

            if (!url.StartsWith("http://", StringComparison.OrdinalIgnoreCase) && !url.StartsWith("https://", StringComparison.OrdinalIgnoreCase))
            {
                url = "https://" + url;
            }

            return url.TrimEnd('/');
        }

        private static string Read(Dictionary<string, object> data, string key)
        {
            object value;
            return data != null && data.TryGetValue(key, out value) && value != null ? Convert.ToString(value) : "";
        }

        private static string Protect(string value)
        {
            if (string.IsNullOrEmpty(value))
            {
                return "";
            }

            return Convert.ToBase64String(ProtectedData.Protect(Encoding.UTF8.GetBytes(value), Entropy, DataProtectionScope.CurrentUser));
        }

        private static string Unprotect(string value)
        {
            if (string.IsNullOrEmpty(value))
            {
                return null;
            }

            return Encoding.UTF8.GetString(ProtectedData.Unprotect(Convert.FromBase64String(value), Entropy, DataProtectionScope.CurrentUser));
        }
    }
}
