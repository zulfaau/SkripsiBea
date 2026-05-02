<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
  <meta charset="utf-8">
  <meta name="viewport"
        content="width=device-width, initial-scale=1">
  <meta name="csrf-token"
        content="{{ csrf_token() }}">
  <title>ScholarBot</title>

  <link rel="preconnect"
        href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap"
        rel="stylesheet" />

  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans bg-slate-50 text-slate-800 antialiased flex flex-col min-h-screen relative">
  <!-- Global background pattern -->
  <div class="fixed inset-0 z-[-1] bg-gradient-to-br from-indigo-50 via-slate-50 to-purple-50"></div>
  <header class="sticky top-0 z-50 border-b border-slate-200 bg-white/80 backdrop-blur-xl shadow-sm">
    <div class="mx-auto flex h-16 w-full max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
      <a href="{{ url('/') }}" class="flex items-center gap-2.5">
        <span class="text-2xl font-bold text-gray-800 tracking-tight">🤖ScholarBot</span>
      </a>

      <nav class="hidden items-center gap-8 text-sm font-semibold md:flex text-gray-500">
        <a href="{{ url('/') }}" class="text-blue-600">Home</a>
        <a href="{{ route('scholarship') }}" class="hover:text-blue-600 transition-colors">Scholarships</a>
        <a href="{{ route('chatbot') }}" class="hover:text-blue-600 transition-colors">Chatbot</a>
        <a href="{{ route('bookmarks') }}" class="hover:text-blue-600 transition-colors">Saved</a>
      </nav>

      <div>
        @auth
          <div x-data="{ open: false }" class="relative">
            <button x-on:click="open = !open" class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-50 text-sm font-bold text-indigo-600 shadow-sm hover:bg-indigo-100 transition-all">
              {{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
            </button>
            <div x-show="open" x-on:click.outside="open = false" style="display: none;" class="absolute right-0 z-50 mt-2 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
              <div class="border-b border-slate-100 px-4 py-3 bg-slate-50">
                <p class="text-sm font-semibold text-slate-900">{{ auth()->user()->name }}</p>
                <p class="text-xs text-slate-500">{{ auth()->user()->email }}</p>
              </div>
              <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full px-4 py-3 text-left text-sm font-semibold text-rose-600 hover:bg-rose-50 transition-colors">Logout</button>
              </form>
            </div>
          </div>
        @else
          <a href="{{ route('login') }}" class="rounded-xl bg-indigo-50 px-6 py-2.5 text-sm font-semibold text-indigo-600 shadow-sm hover:bg-indigo-100 transition-all">Login</a>
        @endauth
      </div>
    </div>
  </header>

  <!-- Full Width Professional Hero -->
  <section class="w-full py-24 sm:py-32 relative overflow-hidden flex-grow-0">
    <!-- Soft Background Elements -->
    <div class="absolute inset-0 bg-white/50"></div>
    <div class="absolute top-0 right-0 -translate-y-12 translate-x-1/3">
      <div class="h-96 w-96 rounded-full bg-indigo-500/10 blur-[120px]"></div>
    </div>
    
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 relative z-10 text-center">
      <h1 class="mx-auto max-w-4xl text-5xl font-extrabold tracking-tight text-gray-800 sm:text-6xl lg:text-7xl">
        Waktunya Cari <span class="text-indigo-500">Beasiswa</span> Impianmu!🎓
      </h1>
      <p class="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-gray-500">
        Ada ribuan beasiswa menunggumu dari dalam hingga luar negeri. Yuk, ngobrol dengan AI kami dan temukan yang paling cocok buat masa depanmu.
      </p>

      <div class="mx-auto mt-10 flex w-full max-w-2xl flex-col gap-4 sm:flex-row">
        <div class="relative flex-1">
          <svg xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.85-5.15a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <input type="text" placeholder="Search scholarships..." class="w-full rounded-xl border border-slate-200 bg-white py-4 pl-12 pr-4 text-gray-800 placeholder:text-gray-400 shadow-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100 transition-all" />
        </div>
        <a href="{{ route('chatbot') }}" class="inline-flex items-center justify-center rounded-xl bg-indigo-500 px-8 py-4 text-base font-semibold text-white shadow-sm hover:bg-indigo-600 hover:-translate-y-0.5 transition-all">
          🤖 Tanya AI
        </a>
      </div>
    </div>
  </section>

  <!-- Full Width Featured Scholarships -->
  <section class="w-full py-24 flex-grow relative z-10">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="text-center max-w-3xl mx-auto">
        <h2 class="text-3xl font-bold tracking-tight text-gray-800 sm:text-4xl">Beasiswa Pilihan 🎓</h2>
        <p class="mt-4 text-lg text-gray-500">Jelajahi peluang beasiswa terbaik yang kami rekomendasikan untukmu.</p>
      </div>

      <div class="mt-16 grid grid-cols-1 gap-8 sm:grid-cols-2 xl:grid-cols-4">
        <!-- Card 1 -->
        <article class="group flex flex-col overflow-hidden rounded-2xl bg-white shadow-md shadow-slate-200/50 border border-slate-100 transition-all hover:-translate-y-1 hover:shadow-xl hover:shadow-indigo-500/10">
          <div class="relative h-48 overflow-hidden">
            <img src="/images/scholarships/lpdp.jpg" alt="LPDP" class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105" />
            <div class="absolute top-4 left-4 flex gap-2">
              <span class="rounded-md bg-emerald-500/90 backdrop-blur px-2.5 py-1 text-xs font-semibold text-white">Fully Funded</span>
            </div>
            <button class="absolute top-4 right-4 rounded-full bg-white/90 p-2 text-slate-400 backdrop-blur hover:text-rose-500 transition-colors shadow-sm" aria-label="Save">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" /></svg>
            </button>
          </div>
          <div class="flex flex-1 flex-col p-6">
            <span class="mb-2 text-xs font-bold uppercase tracking-wider text-indigo-600">Domestic</span>
            <h3 class="text-xl font-bold text-slate-900 line-clamp-1">LPDP Scholarship</h3>
            <p class="mt-2 text-sm text-slate-600 line-clamp-2">Various Universities</p>
            <div class="mt-auto pt-6 flex items-center justify-between text-sm font-medium text-slate-500">
              <div class="flex items-center gap-1.5"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg> Indonesia</div>
              <div>May 30, 2026</div>
            </div>
          </div>
        </article>

        <!-- Card 2 -->
        <a href="{{ route('scholarship.detail', 1) }}" class="group flex flex-col overflow-hidden rounded-2xl bg-[#F1F5F9] border border-slate-200 transition-all hover:-translate-y-1 hover:shadow-lg hover:shadow-blue-900/5">
          <div class="relative h-48 overflow-hidden">
            <img src="/images/scholarships/chevening.jpg" alt="Chevening" class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105" />
            <div class="absolute top-4 left-4 flex gap-2">
              <span class="rounded-md bg-emerald-500/90 backdrop-blur px-2.5 py-1 text-xs font-semibold text-white">Fully Funded</span>
            </div>
            <button class="absolute top-4 right-4 rounded-full bg-white/90 p-2 text-slate-400 backdrop-blur hover:text-rose-500 transition-colors shadow-sm" aria-label="Save">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" /></svg>
            </button>
          </div>
          <div class="flex flex-1 flex-col p-6">
            <span class="mb-2 text-xs font-bold uppercase tracking-wider text-indigo-600">International</span>
            <h3 class="text-xl font-bold text-slate-900 line-clamp-1">Chevening Scholarship</h3>
            <p class="mt-2 text-sm text-slate-600 line-clamp-2">UK Universities</p>
            <div class="mt-auto pt-6 flex items-center justify-between text-sm font-medium text-slate-500">
              <div class="flex items-center gap-1.5"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg> UK</div>
              <div>Jun 15, 2026</div>
            </div>
          </div>
        </a>

        <!-- Card 3 -->
        <a href="{{ route('scholarship.detail', 1) }}" class="group flex flex-col overflow-hidden rounded-2xl bg-[#F1F5F9] border border-slate-200 transition-all hover:-translate-y-1 hover:shadow-lg hover:shadow-blue-900/5">
          <div class="relative h-48 overflow-hidden">
            <img src="/images/scholarships/fulbright.jpg" alt="Fulbright" class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105" />
            <div class="absolute top-4 left-4 flex gap-2">
              <span class="rounded-md bg-emerald-500/90 backdrop-blur px-2.5 py-1 text-xs font-semibold text-white">Fully Funded</span>
            </div>
            <button class="absolute top-4 right-4 rounded-full bg-white/90 p-2 text-slate-400 backdrop-blur hover:text-rose-500 transition-colors shadow-sm" aria-label="Save">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" /></svg>
            </button>
          </div>
          <div class="flex flex-1 flex-col p-6">
            <span class="mb-2 text-xs font-bold uppercase tracking-wider text-indigo-600">International</span>
            <h3 class="text-xl font-bold text-slate-900 line-clamp-1">Fulbright Scholarship</h3>
            <p class="mt-2 text-sm text-slate-600 line-clamp-2">US Universities</p>
            <div class="mt-auto pt-6 flex items-center justify-between text-sm font-medium text-slate-500">
              <div class="flex items-center gap-1.5"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg> USA</div>
              <div>Jul 1, 2026</div>
            </div>
          </div>
        </a>

        <!-- Card 4 -->
        <a href="{{ route('scholarship.detail', 1) }}" class="group flex flex-col overflow-hidden rounded-2xl bg-[#F1F5F9] border border-slate-200 transition-all hover:-translate-y-1 hover:shadow-lg hover:shadow-blue-900/5">
          <div class="relative h-48 overflow-hidden">
            <img src="/images/scholarships/mext.jpg" alt="MEXT" class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105" />
            <div class="absolute top-4 left-4 flex gap-2">
              <span class="rounded-md bg-emerald-500/90 backdrop-blur px-2.5 py-1 text-xs font-semibold text-white">Fully Funded</span>
            </div>
            <button class="absolute top-4 right-4 rounded-full bg-white/90 p-2 text-slate-400 backdrop-blur hover:text-rose-500 transition-colors shadow-sm" aria-label="Save">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" /></svg>
            </button>
          </div>
          <div class="flex flex-1 flex-col p-6">
            <span class="mb-2 text-xs font-bold uppercase tracking-wider text-indigo-600">International</span>
            <h3 class="text-xl font-bold text-slate-900 line-clamp-1">MEXT Scholarship</h3>
            <p class="mt-2 text-sm text-slate-600 line-clamp-2">Japanese Universities</p>
            <div class="mt-auto pt-6 flex items-center justify-between text-sm font-medium text-slate-500">
              <div class="flex items-center gap-1.5"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg> Japan</div>
              <div>May 20, 2026</div>
            </div>
          </div>
        </article>
      </div>

      <div class="mt-12 text-center">
        <a href="{{ route('scholarship') }}" class="inline-flex rounded-xl bg-indigo-50 px-8 py-3 text-sm font-semibold text-indigo-600 hover:bg-indigo-100 transition-all">View All Scholarships</a>
      </div>
    </div>
  </section>

  <!-- Full Width Stats -->
  <section class="w-full py-20 flex-grow-0 relative z-10">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="grid grid-cols-1 gap-8 divide-y divide-slate-200 md:grid-cols-3 md:divide-x md:divide-y-0 text-center">
        <div class="px-6 py-4 flex flex-col items-center">
          <svg class="h-10 w-10 text-indigo-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
          <p class="text-5xl font-extrabold text-indigo-600">1000+</p>
          <p class="mt-3 text-lg font-medium text-slate-500">Informasi Beasiswa</p>
        </div>
        <div class="px-6 py-4 flex flex-col items-center">
          <svg class="h-10 w-10 text-indigo-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          <p class="text-5xl font-extrabold text-indigo-600">50+</p>
          <p class="mt-3 text-lg font-medium text-slate-500">Negara Tujuan</p>
        </div>
        <div class="px-6 py-4 flex flex-col items-center">
          <svg class="h-10 w-10 text-indigo-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          <p class="text-5xl font-extrabold text-indigo-600">Daily</p>
          <p class="mt-3 text-lg font-medium text-slate-500">Update Berkala</p>
        </div>
      </div>
    </div>
  </section>

  <footer class="w-full bg-[#FFFFFF] py-16 text-gray-500 flex-grow-0 border-t border-slate-200">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="grid grid-cols-1 gap-12 md:grid-cols-3 md:gap-8">
        <div>
          <a href="{{ url('/') }}" class="flex items-center gap-2.5 text-gray-800">
            <span class="text-2xl font-bold tracking-tight">🤖ScholarBot</span>
          </a>
          <p class="mt-4 text-sm leading-relaxed text-gray-500 max-w-xs">
            Platform pintar untuk menemukan beasiswa yang tepat untuk masa depanmu.
          </p>
        </div>
        
        <div>
          <h3 class="text-sm font-bold tracking-wider text-gray-800 uppercase">Navigation</h3>
          <div class="mt-4 flex flex-col gap-3 text-sm font-medium">
            <a href="{{ url('/') }}" class="text-blue-600 transition-colors">Home</a>
            <a href="{{ route('scholarship') }}" class="hover:text-blue-600 transition-colors">Scholarships</a>
            <a href="{{ route('chatbot') }}" class="hover:text-blue-600 transition-colors">Chatbot</a>
            <a href="{{ route('bookmarks') }}" class="hover:text-blue-600 transition-colors">Saved</a>
          </div>
        </div>
        
        <div>
          <h3 class="text-sm font-bold tracking-wider text-gray-800 uppercase">Mulai Sekarang</h3>
          <p class="mt-4 text-sm text-gray-500 mb-4 font-medium">Punya pertanyaan soal beasiswa? Tanya AI sekarang.</p>
          <a href="{{ route('chatbot') }}" class="inline-flex items-center justify-center rounded-xl bg-indigo-500 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-600 transition-all">
            Tanya AI
          </a>
        </div>
      </div>
      <div class="mt-16 border-t border-slate-200 pt-8 flex items-center justify-between">
        <p class="text-sm text-gray-400">© 2026 ScholarBot. All rights reserved.</p>
      </div>
    </div>
  </footer>
</body>
</html>
