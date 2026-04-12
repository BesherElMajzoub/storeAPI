<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google Login Test (Blade)</title>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            background-color: #f4f7f6;
            margin: 0;
        }
        .container {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            text-align: center;
        }
        h2 { color: #333; margin-bottom: 1.5rem; }
        #status { margin-top: 1rem; font-weight: bold; color: #555; }
        .log-area {
            margin-top: 2rem;
            text-align: left;
            background: #2d2d2d;
            color: #50fa7b;
            padding: 1rem;
            border-radius: 8px;
            width: 80%;
            max-width: 600px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.9rem;
            overflow-x: auto;
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Google Login Test</h2>

        <div id="g_id_onload"
             data-client_id="{{ config('services.google.client_id') }}"
             data-callback="handleCredentialResponse"
             data-auto_prompt="false">
        </div>

        <div class="g_id_signin" data-type="standard"></div>

        <div id="status">Ready to Login</div>
    </div>

    <div id="logArea" class="log-area"></div>

    <script>
        function handleCredentialResponse(response) {
            const statusDiv = document.getElementById('status');
            const logArea = document.getElementById('logArea');
            
            statusDiv.innerText = "Sending to API...";
            statusDiv.style.color = "blue";

            console.log("ID TOKEN RECEIVED:", response.credential);

            // Using fetch ensures the page does NOT reload
            fetch("{{ url('/api/v1/auth/google') }}", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json"
                },
                body: JSON.stringify({
                    id_token: response.credential,
                    device_name: "web_test"
                })
            })
            .then(r => r.json())
            .then(data => {
                console.log("API RESPONSE:", data);
                statusDiv.innerText = data.success ? "Login Successful! Check Console." : "Login Failed: " + data.message;
                statusDiv.style.color = data.success ? "green" : "red";
                
                // Show data in UI so it doesn't "disappear" from mind
                logArea.style.display = "block";
                logArea.innerText = JSON.stringify(data, null, 2);
            })
            .catch(error => {
                console.error("FETCH ERROR:", error);
                statusDiv.innerText = "Network Error. Check Console.";
                statusDiv.style.color = "red";
            });
        }
    </script>
</body>
</html>
