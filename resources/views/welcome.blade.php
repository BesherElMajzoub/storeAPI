<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Otantik Queen - API Server</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background-color: #0f0e0b;
            color: #d4af37;
            font-family: 'Outfit', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            overflow: hidden;
            position: relative;
        }
        /* Dynamic ambient background glow */
        .ambient-glow {
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.08) 0%, rgba(0,0,0,0) 70%);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1;
            pointer-events: none;
            animation: pulse 8s infinite alternate ease-in-out;
        }
        .container {
            position: relative;
            z-index: 2;
            text-align: center;
            padding: 2rem;
            animation: fadeIn 1.5s ease-out forwards;
        }
        .logo-wrapper {
            position: relative;
            display: inline-block;
            border-radius: 24px;
            padding: 8px;
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.15) 0%, rgba(15, 14, 11, 0.8) 100%);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6),
                        inset 0 1px 0 rgba(212, 175, 55, 0.2);
            transition: transform 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275), box-shadow 0.5s ease;
            cursor: pointer;
        }
        .logo-wrapper:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 30px 60px rgba(212, 175, 55, 0.15),
                        inset 0 1px 0 rgba(212, 175, 55, 0.4);
        }
        .logo {
            max-width: 420px;
            width: 100%;
            height: auto;
            border-radius: 18px;
            display: block;
            object-fit: cover;
        }
        .title {
            margin-top: 2rem;
            font-size: 1.5rem;
            font-weight: 600;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #f1e5c2;
            text-shadow: 0 2px 4px rgba(0,0,0,0.5);
            opacity: 0;
            animation: slideUp 1.2s cubic-bezier(0.19, 1, 0.22, 1) 0.5s forwards;
        }
        .subtitle {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            font-weight: 300;
            letter-spacing: 1px;
            color: #8c8266;
            opacity: 0;
            animation: slideUp 1.2s cubic-bezier(0.19, 1, 0.22, 1) 0.8s forwards;
        }
        
        /* Keyframe animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes pulse {
            0% { transform: translate(-50%, -50%) scale(0.9); opacity: 0.6; }
            100% { transform: translate(-50%, -50%) scale(1.1); opacity: 1; }
        }
        
        /* Responsive adjustments */
        @media (max-width: 480px) {
            .logo {
                max-width: 290px;
            }
            .title {
                font-size: 1.2rem;
                letter-spacing: 2px;
            }
            .subtitle {
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="ambient-glow"></div>
    <div class="container">
        <div class="logo-wrapper">
            <img class="logo" src="{{ asset('otantik.png') }}" alt="Otantik Queen">
        </div>
        <h1 class="title">Otantik Queen</h1>
        <p class="subtitle">Premium E-Commerce API Server</p>
    </div>
</body>
</html>
