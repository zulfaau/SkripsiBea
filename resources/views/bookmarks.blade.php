<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
  <meta charset="utf-8">
  <meta name="viewport"
        content="width=device-width, initial-scale=1">
  <meta name="csrf-token"
        content="{{ csrf_token() }}">
  <title>Saved Scholarships - ScholarFind</title>

  <link rel="preconnect"
        href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap"
        rel="stylesheet" />

  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans bg-slate-100 text-slate-800 antialiased">
  <header class="sticky top-0 z-50 border-b border-slate-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 w-full max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
      <a href="{{ url('/') }}"
         class="flex items-center gap-2.5">
        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-r from-indigo-600 to-violet-600 text-white">
          <svg xmlns="http://www.w3.org/2000/svg"
               class="h-4 w-4"
               fill="none"
               viewBox="0 0 24 24"
               stroke="currentColor"
               stroke-width="2">
            <path stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M8 10h8m-8 4h5M6 19h12a2 2 0 002-2V7a2 2 0 00-2-2h-3.5a1 1 0 01-.8-.4l-.9-1.2a1 1 0 00-.8-.4h-2a1 1 0 00-.8.4l-.9 1.2a1 1 0 01-.8.4H6a2 2 0 00-2 2v10a2 2 0 002 2z" />
          </svg>
        </span>
        <span class="text-xl font-bold">ScholarFind</span>
      </a>

      <nav class="hidden items-center gap-2 text-sm font-medium md:flex">
        <a href="{{ url('/') }}"
           class="rounded-full px-3 py-1.5 text-slate-500 hover:text-slate-700">Home</a>
        <a href="{{ route('scholarship') }}"
           class="rounded-full px-3 py-1.5 text-slate-500 hover:text-slate-700">Scholarships</a>
        <a href="{{ route('chatbot') }}"
           class="rounded-full px-3 py-1.5 text-slate-500 hover:text-slate-700">Chatbot</a>
        <a href="{{ route('bookmarks') }}"
           class="rounded-full bg-indigo-50 px-3 py-1.5 text-indigo-600">Saved</a>
      </nav>

      <div>
        @auth
          <div x-data="{ open: false }"
               class="relative">
            <button x-on:click="open = !open"
                    class="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-r from-indigo-600 to-violet-600 text-sm font-bold text-white">
              {{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
            </button>

            <div x-show="open"
                 x-on:click.outside="open = false"
                 style="display: none;"
                 class="absolute right-0 z-50 mt-2 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
              <div class="border-b border-slate-100 px-4 py-3">
                <p class="text-sm font-semibold text-slate-800">{{ auth()->user()->name }}</p>
                <p class="text-xs text-slate-500">{{ auth()->user()->email }}</p>
              </div>
              <form method="POST"
                    action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="w-full px-4 py-3 text-left text-sm font-semibold text-rose-600 hover:bg-rose-50">Logout</button>
              </form>
            </div>
          </div>
        @else
          <a href="{{ route('login') }}"
             class="rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-6 py-2 text-sm font-semibold text-white hover:opacity-95">Login</a>
        @endauth
      </div>
    </div>
  </header>

  <main class="mx-auto w-full max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="flex items-center gap-3">
      <svg xmlns="http://www.w3.org/2000/svg"
           class="h-8 w-8 text-indigo-600"
           viewBox="0 0 24 24"
           fill="none"
           stroke="currentColor"
           stroke-width="2">
        <path stroke-linecap="round"
              stroke-linejoin="round"
              d="M12 21l-1.45-1.32C5.4 15.04 2 11.95 2 8.15 2 5.06 4.42 2.65 7.5 2.65c1.74 0 3.41.81 4.5 2.09 1.09-1.28 2.76-2.09 4.5-2.09 3.08 0 5.5 2.41 5.5 5.5 0 3.8-3.4 6.89-8.55 11.54L12 21z" />
      </svg>
      <h1 class="text-4xl font-bold text-slate-800">Saved Scholarships</h1>
    </div>
    <p class="mt-2 text-slate-500">You have 3 saved scholarships</p>

    <section class="mt-8 grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
      @php
        $saved = [['title' => 'LPDP Scholarship', 'university' => 'Various Universities', 'country' => 'Indonesia', 'date' => 'May 30, 2026', 'image' => '/images/scholarships/lpdp.jpg', 'region' => 'Domestic'], ['title' => 'Chevening Scholarship', 'university' => 'UK Universities', 'country' => 'United Kingdom', 'date' => 'Jun 15, 2026', 'image' => '/images/scholarships/chevening.jpg', 'region' => 'International'], ['title' => 'Fulbright Scholarship', 'university' => 'US Universities', 'country' => 'United States', 'date' => 'Jul 1, 2026', 'image' => '/images/scholarships/fulbright.jpg', 'region' => 'International']];
      @endphp

      @foreach ($saved as $item)
        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
          <img src="{{ $item['image'] }}"
               alt="{{ $item['title'] }}"
               class="h-32 w-full object-cover" />
          <div class="p-4">
            <div class="mb-3 flex gap-2 text-[11px] font-semibold text-white">
              <span class="rounded-full bg-emerald-500 px-2 py-0.5">Fully Funded</span>
              <span class="rounded-full bg-violet-500 px-2 py-0.5">{{ $item['region'] }}</span>
            </div>
            <div class="flex items-start justify-between gap-3">
              <div>
                <h3 class="text-2xl font-bold text-slate-800">{{ $item['title'] }}</h3>
                <p class="text-sm text-slate-500">{{ $item['university'] }}</p>
              </div>
              <button class="mt-1 text-slate-400 hover:text-indigo-600"
                      aria-label="Saved">
                <svg xmlns="http://www.w3.org/2000/svg"
                     class="h-5 w-5"
                     viewBox="0 0 24 24"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="2">
                  <path stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z" />
                </svg>
              </button>
            </div>
            <div class="mt-3 flex items-center gap-3 text-xs text-slate-500">
              <span>{{ $item['country'] }}</span>
              <span>{{ $item['date'] }}</span>
            </div>
            <span class="mt-3 inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-600">S2</span>
          </div>
        </article>
      @endforeach
    </section>
  </main>
</body>

</html>
