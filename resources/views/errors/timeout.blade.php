<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f6f7f4">
    <title>Task took too long — Sanctum Keeper</title>
    <style>
        :root {
            color-scheme: light;
            font-family: "Segoe UI", Arial, sans-serif;
            color: #22251f;
            background: #f6f7f4;
        }

        body {
            box-sizing: border-box;
            display: grid;
            min-height: 100vh;
            margin: 0;
            padding: 24px;
            place-items: center;
        }

        main {
            box-sizing: border-box;
            width: min(100%, 560px);
            padding: 36px;
            border: 1px solid #e5e8e2;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 16px 48px rgb(34 37 31 / 7%);
        }

        .eyebrow {
            color: #718071;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .12em;
        }

        h1 {
            margin: 12px 0;
            font-size: clamp(24px, 5vw, 32px);
        }

        p,
        li {
            color: #62685f;
            line-height: 1.65;
        }

        ul {
            padding-left: 22px;
        }

        a {
            display: inline-block;
            margin-top: 12px;
            padding: 11px 16px;
            border-radius: 8px;
            background: #315d46;
            color: #fff;
            font-weight: 700;
            text-decoration: none;
        }

        a:focus-visible {
            outline: 3px solid #91b79d;
            outline-offset: 3px;
        }
    </style>
</head>

<body>
    <main>
        <div class="eyebrow">SANCTUM KEEPER</div>
        <h1>This task took longer than expected</h1>
        <p>{{ $message }} If the task may have started changing files, check its status before retrying.</p>
        <h2>What you can do</h2>
        <ul>
            @foreach ($nextSteps as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ul>
        <a href="{{ route('home') }}">Return to Keeper</a>
    </main>
</body>

</html>
