<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
  <meta charset="utf-8">
  <meta name="viewport"
        content="width=device-width, initial-scale=1">
  <meta name="csrf-token"
        content="{{ csrf_token() }}">
  <title>Scholarships - ScholarBot</title>

  <link rel="preconnect"
        href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap"
        rel="stylesheet" />

  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans bg-slate-50 text-slate-800 antialiased flex flex-col min-h-screen relative">
  <!-- Global background pattern -->
  <div class="fixed inset-0 z-[-1] bg-gradient-to-br from-indigo-50 via-slate-50 to-purple-50"></div>
  
  <header class="sticky top-0 z-50 bg-white/70 backdrop-blur-xl shadow-sm">
    <div class="mx-auto flex h-16 w-full max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
      <a href="{{ url('/') }}" class="flex items-center gap-2.5">
        <span class="text-2xl font-bold text-gray-800 tracking-tight">🤖ScholarBot</span>
      </a>

      <nav class="hidden items-center gap-2 text-sm font-medium md:flex">
        <a href="{{ url('/') }}"
           class="rounded-full px-3 py-1.5 text-slate-500 hover:text-slate-700">Home</a>
        <a href="{{ route('scholarship') }}"
           class="rounded-full bg-indigo-50 px-3 py-1.5 text-indigo-600">Scholarships</a>
        <a href="{{ route('chatbot') }}"
           class="rounded-full px-3 py-1.5 text-slate-500 hover:text-slate-700">Chatbot</a>
        <a href="{{ route('bookmarks') }}"
           class="rounded-full px-3 py-1.5 text-slate-500 hover:text-slate-700">Saved</a>
      </nav>

      <div>
        @auth
          <div x-data="{ open: false }"
               class="relative">
            <button x-on:click="open = !open"
                    class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-50 text-sm font-bold text-indigo-600 shadow-sm hover:bg-indigo-100 transition-all">
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
             class="rounded-xl bg-indigo-50 px-6 py-2.5 text-sm font-semibold text-indigo-600 shadow-sm hover:bg-indigo-100 transition-all">Login</a>
        @endauth
      </div>
    </div>
  </header>

  <main class="flex-grow mx-auto w-full max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <h1 class="text-4xl font-bold text-slate-800">Yuk, Jelajahi Beasiswa! ✨</h1>
    <p class="mt-2 text-slate-500">Ada 8 beasiswa yang bisa kamu cek sekarang</p>

    <section class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-12">
      <aside class="lg:col-span-3">
        <div class="sticky top-24 rounded-3xl border border-slate-100 bg-white p-8 shadow-sm">
          <h2 class="text-xl font-semibold text-slate-800">Filters</h2>

          <div class="mt-6">
            <h3 class="text-sm font-semibold text-slate-700">Negara Tujuan</h3>
            <div class="mt-3 space-y-3 text-sm text-slate-600">
              <label class="flex items-center gap-2"><input type="radio"
                       name="destination"
                       checked
                       class="text-indigo-600 focus:ring-indigo-500"> All</label>
              <label class="flex items-center gap-2"><input type="radio"
                       name="destination"
                       class="text-indigo-600 focus:ring-indigo-500"> Domestic (Dalam Negeri)</label>
              <label class="flex items-center gap-2"><input type="radio"
                       name="destination"
                       class="text-indigo-600 focus:ring-indigo-500"> International (Luar Negeri)</label>
            </div>
          </div>

          <div class="mt-8">
            <h3 class="text-sm font-semibold text-slate-700">Jenjang Studi</h3>
            <div class="mt-3 space-y-3 text-sm text-slate-600">
              <label class="flex items-center gap-2"><input type="radio"
                       name="degree"
                       checked
                       class="text-indigo-600 focus:ring-indigo-500"> Semua Jenjang</label>
              <label class="flex items-center gap-2"><input type="radio"
                       name="degree"
                       class="text-indigo-600 focus:ring-indigo-500"> S1</label>
              <label class="flex items-center gap-2"><input type="radio"
                       name="degree"
                       class="text-indigo-600 focus:ring-indigo-500"> S2</label>
              <label class="flex items-center gap-2"><input type="radio"
                       name="degree"
                       class="text-indigo-600 focus:ring-indigo-500"> S3</label>
            </div>
          </div>

          <button class="mt-8 w-full rounded-xl border border-indigo-200 px-4 py-2 text-sm font-semibold text-indigo-600 hover:bg-indigo-50">Reset Filters</button>
        </div>
      </aside>

      <div class="lg:col-span-9 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3">
        @php
          $scholarships = [
              ['title' => 'LPDP Scholarship', 'university' => 'Various Universities', 'country' => 'Indonesia', 'date' => 'May 30, 2026', 'image' => '/images/scholarships/lpdp.jpg', 'region' => 'Domestic'],
              ['title' => 'Chevening Scholarship', 'university' => 'UK Universities', 'country' => 'United Kingdom', 'date' => 'Jun 15, 2026', 'image' => '/images/scholarships/chevening.jpg', 'region' => 'International'],
              ['title' => 'Fulbright Scholarship', 'university' => 'US Universities', 'country' => 'United States', 'date' => 'Jul 1, 2026', 'image' => '/images/scholarships/fulbright.jpg', 'region' => 'International'],
              ['title' => 'MEXT Scholarship', 'university' => 'Japanese Universities', 'country' => 'Japan', 'date' => 'May 20, 2026', 'image' => '/images/scholarships/mext.jpg', 'region' => 'International'],
              ['title' => 'Australia Awards', 'university' => 'Australian Universities', 'country' => 'Australia', 'date' => 'Jun 30, 2026', 'image' => '/images/scholarships/chevening.jpg', 'region' => 'International'],
              ['title' => 'DAAD Scholarship', 'university' => 'German Universities', 'country' => 'Germany', 'date' => 'Aug 31, 2026', 'image' => '/images/scholarships/fulbright.jpg', 'region' => 'International'],
              ['title' => 'Beasiswa Unggulan', 'university' => 'Indonesian Universities', 'country' => 'Indonesia', 'date' => 'Sep 12, 2026', 'image' => '/images/scholarships/lpdp.jpg', 'region' => 'Domestic'],
              ['title' => 'Swiss Government Excellence', 'university' => 'Swiss Universities', 'country' => 'Switzerland', 'date' => 'Nov 15, 2026', 'image' => '/images/scholarships/mext.jpg', 'region' => 'International'],
          ];
        @endphp

        @foreach ($scholarships as $item)
          <a href="{{ route('scholarship.detail', $loop->iteration) }}" class="block group overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-sm transition-all duration-300 hover:-translate-y-1.5 hover:shadow-xl hover:shadow-indigo-500/10">
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
                  <h3 class="text-xl font-bold text-slate-800">{{ $item['title'] }}</h3>
                  <p class="text-sm text-slate-500">{{ $item['university'] }}</p>
                </div>
                <button class="mt-1 text-slate-400 hover:text-rose-500" aria-label="Save">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" />
                  </svg>
                </button>
              </div>
              <div class="mt-3 flex items-center gap-3 text-xs text-slate-500">
                <span>{{ $item['country'] }}</span>
                <span>{{ $item['date'] }}</span>
              </div>
            </div>
          </a>
        @endforeach
      </div>
    </section>
  </main>
  <footer class="w-full bg-[#FFFFFF] py-16 text-gray-500 flex-grow-0 border-t border-slate-200 mt-20 relative z-10">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="grid grid-cols-1 gap-12 md:grid-cols-3 md:gap-8 text-center md:text-left">
        <div>
          <a href="{{ url('/') }}" class="flex items-center gap-2.5 justify-center md:justify-start text-gray-800">
            <span class="text-2xl font-bold tracking-tight">🤖ScholarBot</span>
          </a>
          <p class="mt-4 text-sm leading-relaxed text-gray-500 max-w-xs mx-auto md:mx-0">
            Platform pintar untuk menemukan beasiswa yang tepat untuk masa depanmu.
          </p>
        </div>
        
        <div class="flex flex-col items-center">
          <h3 class="text-sm font-bold tracking-wider text-gray-800 uppercase mb-4">Navigation</h3>
          <div class="flex flex-col gap-3 text-sm font-medium">
            <a href="{{ url('/') }}" class="hover:text-blue-600 transition-colors">Home</a>
            <a href="{{ route('scholarship') }}" class="text-blue-600 transition-colors">Scholarships</a>
            <a href="{{ route('chatbot') }}" class="hover:text-blue-600 transition-colors">Chatbot</a>
            <a href="{{ route('bookmarks') }}" class="hover:text-blue-600 transition-colors">Saved</a>
          </div>
        </div>
        
        <div class="flex flex-col items-center md:items-end">
          <h3 class="text-sm font-bold tracking-wider text-gray-800 uppercase mb-4">Mulai Sekarang</h3>
          <p class="text-sm text-gray-500 mb-4 font-medium">Punya pertanyaan soal beasiswa? Tanya AI sekarang.</p>
          <a href="{{ route('chatbot') }}" class="inline-flex items-center justify-center rounded-xl bg-indigo-500 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-600 transition-all">
            Tanya AI
          </a>
        </div>
      </div>
      <div class="mt-16 border-t border-slate-200 pt-8 text-center">
        <p class="text-sm text-gray-400">© 2026 ScholarBot. All rights reserved.</p>
      </div>
    </div>
  </footer>
</body>

</html>
