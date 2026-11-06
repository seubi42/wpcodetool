using System;
using System.Collections;
using System.Collections.Generic;
using System.IO;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Threading.Tasks;
using System.Web.Script.Serialization;

namespace SignaConnectUpload
{
    public sealed class Team
    {
        public int Id { get; set; }
        public string Label { get; set; }

        public override string ToString()
        {
            return Label;
        }
    }

    public sealed class Profile
    {
        public string Token { get; set; }
        public string UserName { get; set; }
        public string Email { get; set; }
        public string AppUrl { get; set; }
        public int MaxUploadMb { get; set; }
        public List<Team> Teams { get; set; }
    }

    public sealed class UploadResult
    {
        public int DocumentId { get; set; }
        public string OpenUrl { get; set; }
    }

    public sealed class ApiException : Exception
    {
        public ApiException(string message, HttpStatusCode status) : base(message)
        {
            Status = status;
        }

        public HttpStatusCode Status { get; private set; }

        /// <summary>Jeton refusé (révoqué depuis « Mon profil », ou utilisateur supprimé).</summary>
        public bool IsUnauthorized
        {
            get { return Status == HttpStatusCode.Unauthorized; }
        }
    }

    /// <summary>
    /// Routes signconnect/v1/client/* du plugin SignaConnect. On passe par ?rest_route=,
    /// qui marche que les permaliens WordPress soient activés ou non.
    /// </summary>
    public sealed class ApiClient
    {
        private const string TokenHeader = "X-SignConnect-Token";
        private static readonly HttpClient Http = CreateHttp();
        private readonly string _baseUrl;

        public ApiClient(string serverUrl)
        {
            _baseUrl = ClientSettings.NormalizeUrl(serverUrl);
        }

        private static HttpClient CreateHttp()
        {
            ServicePointManager.SecurityProtocol |= SecurityProtocolType.Tls12;
            var http = new HttpClient { Timeout = TimeSpan.FromMinutes(5) };
            http.DefaultRequestHeaders.UserAgent.ParseAdd("SignaConnectUpload/1.0");
            return http;
        }

        public static string DeviceName
        {
            get { return Environment.MachineName + " (" + Environment.UserName + ")"; }
        }

        public async Task<Profile> LoginAsync(string login, string password)
        {
            var body = new FormUrlEncodedContent(new Dictionary<string, string>
            {
                { "login", login },
                { "password", password },
                { "device", DeviceName },
            });

            return ToProfile(await SendAsync(HttpMethod.Post, "login", null, body));
        }

        public async Task<Profile> MeAsync(string token)
        {
            return ToProfile(await SendAsync(HttpMethod.Get, "me", token, null));
        }

        public async Task LogoutAsync(string token)
        {
            await SendAsync(HttpMethod.Post, "logout", token, new StringContent(""));
        }

        public async Task<UploadResult> UploadAsync(string token, string path, int teamId)
        {
            using (var stream = File.OpenRead(path))
            {
                var file = new StreamContent(stream);
                file.Headers.ContentType = new MediaTypeHeaderValue("application/pdf");

                var name = Path.GetFileName(path);

                if (!name.EndsWith(".pdf", StringComparison.OrdinalIgnoreCase))
                {
                    name += ".pdf";
                }

                // Nom réel dans un champ texte UTF-8 ; l'en-tête du fichier garde un nom ASCII,
                // car .NET Framework encode les noms accentués d'une façon que PHP ne lit pas.
                var body = new MultipartFormDataContent();
                body.Add(new StringContent(teamId.ToString()), "team_id");
                body.Add(new StringContent(name, Encoding.UTF8), "filename");
                body.Add(file, "file", "document.pdf");

                var data = await SendAsync(HttpMethod.Post, "upload", token, body);

                return new UploadResult
                {
                    DocumentId = ToInt(Get(data, "document_id")),
                    OpenUrl = Convert.ToString(Get(data, "open_url")),
                };
            }
        }

        private async Task<Dictionary<string, object>> SendAsync(HttpMethod method, string route, string token, HttpContent content)
        {
            if (string.IsNullOrEmpty(_baseUrl))
            {
                throw new ApiException("Indiquez l'adresse du site SignaConnect.", 0);
            }

            var request = new HttpRequestMessage(method, _baseUrl + "/?rest_route=" + Uri.EscapeDataString("/signconnect/v1/client/" + route));

            if (!string.IsNullOrEmpty(token))
            {
                request.Headers.Add(TokenHeader, token);
            }

            request.Content = content;
            HttpResponseMessage response;

            try
            {
                response = await Http.SendAsync(request);
            }
            catch (HttpRequestException exception)
            {
                throw new ApiException("Le site " + _baseUrl + " ne répond pas (" + Innermost(exception).Message + ").", 0);
            }
            catch (TaskCanceledException)
            {
                throw new ApiException("Le site " + _baseUrl + " ne répond pas (délai dépassé).", 0);
            }

            var text = await response.Content.ReadAsStringAsync();
            Dictionary<string, object> data = null;

            try
            {
                data = new JavaScriptSerializer { MaxJsonLength = int.MaxValue }.Deserialize<Dictionary<string, object>>(text);
            }
            catch (Exception)
            {
                data = null;
            }

            if (!response.IsSuccessStatusCode)
            {
                var message = data != null ? Convert.ToString(Get(data, "message")) : "";

                if (string.IsNullOrEmpty(message))
                {
                    message = response.StatusCode == HttpStatusCode.NotFound
                        ? "SignaConnect n'est pas installé sur ce site, ou sa version est trop ancienne (0.7.0 minimum)."
                        : "Erreur du serveur (" + (int) response.StatusCode + ").";
                }

                throw new ApiException(message, response.StatusCode);
            }

            if (data == null)
            {
                throw new ApiException("Réponse inattendue du site : est-ce bien l'adresse de SignaConnect ?", response.StatusCode);
            }

            return data;
        }

        private static Profile ToProfile(Dictionary<string, object> data)
        {
            var user = Get(data, "user") as Dictionary<string, object>;
            var profile = new Profile
            {
                Token = Convert.ToString(Get(data, "token")),
                UserName = Convert.ToString(Get(user, "name")),
                Email = Convert.ToString(Get(user, "email")),
                AppUrl = Convert.ToString(Get(data, "app_url")),
                MaxUploadMb = ToInt(Get(data, "max_upload_mb")),
                Teams = new List<Team>(),
            };
            var teams = Get(data, "teams") as IEnumerable;

            if (teams != null)
            {
                foreach (var item in teams)
                {
                    var team = item as Dictionary<string, object>;

                    if (team != null)
                    {
                        profile.Teams.Add(new Team { Id = ToInt(Get(team, "id")), Label = Convert.ToString(Get(team, "label")) });
                    }
                }
            }

            return profile;
        }

        private static object Get(Dictionary<string, object> data, string key)
        {
            object value;
            return data != null && data.TryGetValue(key, out value) ? value : null;
        }

        private static int ToInt(object value)
        {
            int result;
            return value != null && int.TryParse(Convert.ToString(value), out result) ? result : 0;
        }

        private static Exception Innermost(Exception exception)
        {
            while (exception.InnerException != null)
            {
                exception = exception.InnerException;
            }

            return exception;
        }
    }
}
