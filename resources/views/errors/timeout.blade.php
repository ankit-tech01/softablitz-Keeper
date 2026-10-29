<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f6f7f4">
    <title>Task took too long — Sanctum Keeper</title>
    @vite(['resources/css/app.css'])
    <style>
        .timeout-shell {
            display: grid;
            min-height: 100vh;
            padding: 24px;
            place-items: center;
        }

        .timeout-card {
            width: min(100%, 580px);
            padding: clamp(24px, 6vw, 40px);
            border: 1px solid #e8eae5;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 2px 7px rgb(28 37 28 / 2%);
        }

        .timeout-brand {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 30px;
        }

        .timeout-brand-mark {
            display: grid;
            width: 38px;
            height: 38px;
            place-items: center;
            border-radius: 12px;
            background: #234d3d;
            color: #edf4e6;
            font-size: 22px;
            font-weight: 700;
        }

        .timeout-brand-name {
            font-size: 19px;
            font-weight: 700;
            letter-spacing: -.6px;
        }

        .timeout-brand-tagline {
            display: block;
            margin-top: 2px;
            color: #9a9e97;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.2px;
        }

        .timeout-eyebrow {
            margin-bottom: 8px;
            color: #858b82;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.2px;
        }

        .timeout-card h1 {
            margin: 0 0 12px;
            font-family: 'Gabriela', serif;
            font-size: clamp(25px, 5vw, 34px);
            font-weight: 400;
            letter-spacing: -1px;
            line-height: 1.2;
        }

        .timeout-card p,
        .timeout-card li {
            color: #858a82;
            line-height: 1.65;
        }

        .timeout-card h2 {
            margin: 24px 0 8px;
            font-size: 13px;
            font-weight: 700;
        }

        .timeout-card ul {
            display: grid;
            gap: 6px;
            margin: 0;
            padding-left: 20px;
        }

        .timeout-return {
            margin-top: 22px;
        }

        .timeout-return:focus-visible {
            outline: 3px solid #91b79d;
            outline-offset: 3px;
        }
    </style>
</head>

<body class="timeout-shell">
    <main class="timeout-card">
        <div class="timeout-brand">
            <span class="timeout-brand-mark">K</span>
            <span><span class="timeout-brand-name">keeper</span><small class="timeout-brand-tagline">THE SANCTUM DESKTOP</small></span>
        </div>
        <div class="timeout-eyebrow">TASK STATUS</div>
        <h1>This task took longer than expected</h1>
        <p>{{ $message }} If the task may have started changing files, check its status before retrying.</p>
        <h2>What you can do</h2>
        <ul>
            @foreach ($nextSteps as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ul>
        <a class="button button-dark timeout-return" href="{{ route('home') }}">Return to Keeper <span aria-hidden="true">→</span></a>
    </main>
</body>

</html>
