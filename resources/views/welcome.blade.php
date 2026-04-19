<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
  <meta charset="utf-8">
  <meta name="viewport"
        content="width=device-width, initial-scale=1">
  <meta name="csrf-token"
        content="{{ csrf_token() }}">
  <title>ScholarFind</title>

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
        <a href="#"
           class="rounded-full bg-indigo-50 px-3 py-1.5 text-indigo-600">Home</a>
        <a href="{{ route('scholarship') }}"
           class="rounded-full px-3 py-1.5 text-slate-500 hover:text-slate-700">Scholarships</a>
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

  <main class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <section class="rounded-3xl bg-indigo-50/60 px-6 py-16 text-center sm:px-10 lg:py-20">
      <h1 class="mx-auto max-w-4xl text-4xl font-extrabold leading-tight text-slate-800 sm:text-5xl lg:text-6xl">
        Find Your Perfect <span class="bg-gradient-to-r from-indigo-600 to-violet-600 bg-clip-text text-transparent">Scholarship</span>
        with AI
      </h1>
      <p class="mx-auto mt-6 max-w-3xl text-lg text-slate-500">
        Discover thousands of scholarship opportunities worldwide. Let our AI chatbot guide you to the perfect match for your academic journey.
      </p>

      <div class="mx-auto mt-8 flex w-full max-w-2xl flex-col gap-3 sm:flex-row">
        <div class="relative flex-1">
          <svg xmlns="http://www.w3.org/2000/svg"
               class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400"
               fill="none"
               viewBox="0 0 24 24"
               stroke="currentColor"
               stroke-width="2">
            <path stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M21 21l-4.35-4.35m1.85-5.15a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <input type="text"
                 placeholder="Search scholarships..."
                 class="w-full rounded-xl border border-slate-200 bg-white py-3 pl-12 pr-4 text-slate-700 placeholder:text-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100" />
        </div>
        <a href="{{ route('chatbot') }}"
           class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-8 py-3 text-base font-semibold text-white hover:opacity-95">
          Start Chat
        </a>
      </div>
    </section>

    <section class="mt-16">
      <div class="text-center">
        <h1 class="text-3xl font-bold text-slate-800 sm:text-4xl">Featured Scholarships</h1>
        <p class="mt-2 text-slate-500">Explore our top scholarship opportunities</p>
      </div>

      <div class="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
          <img src="/images/scholarships/lpdp.jpg"
               alt="LPDP Scholarship"
               class="h-32 w-full object-cover" />
          <div class="p-4">
            <div class="mb-3 flex gap-2 text-[11px] font-semibold text-white">
              <span class="rounded-full bg-emerald-500 px-2 py-0.5">Fully Funded</span>
              <span class="rounded-full bg-violet-500 px-2 py-0.5">Domestic</span>
            </div>
            <h3 class="text-xl font-bold text-slate-800">LPDP Scholarship</h3>
            <p class="text-sm text-slate-500">Various Universities</p>
            <div class="mt-3 flex items-center gap-3 text-xs text-slate-500">
              <span>Indonesia</span>
              <span>May 30, 2026</span>
            </div>
          </div>
        </article>

        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
          <img src="/images/scholarships/chevening.jpg"
               alt="Chevening Scholarship"
               class="h-32 w-full object-cover" />
          <div class="p-4">
            <div class="mb-3 flex gap-2 text-[11px] font-semibold text-white">
              <span class="rounded-full bg-emerald-500 px-2 py-0.5">Fully Funded</span>
              <span class="rounded-full bg-violet-500 px-2 py-0.5">International</span>
            </div>
            <h3 class="text-xl font-bold text-slate-800">Chevening Scholarship</h3>
            <p class="text-sm text-slate-500">UK Universities</p>
            <div class="mt-3 flex items-center gap-3 text-xs text-slate-500">
              <span>United Kingdom</span>
              <span>Jun 15, 2026</span>
            </div>
          </div>
        </article>

        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
          <img src="/images/scholarships/fulbright.jpg"
               alt="Fulbright Scholarship"
               class="h-32 w-full object-cover" />
          <div class="p-4">
            <div class="mb-3 flex gap-2 text-[11px] font-semibold text-white">
              <span class="rounded-full bg-emerald-500 px-2 py-0.5">Fully Funded</span>
              <span class="rounded-full bg-violet-500 px-2 py-0.5">International</span>
            </div>
            <h3 class="text-xl font-bold text-slate-800">Fulbright Scholarship</h3>
            <p class="text-sm text-slate-500">US Universities</p>
            <div class="mt-3 flex items-center gap-3 text-xs text-slate-500">
              <span>United States</span>
              <span>Jul 1, 2026</span>
            </div>
          </div>
        </article>

        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
          <img src="/images/scholarships/mext.jpg"
               alt="MEXT Scholarship"
               class="h-32 w-full object-cover" />
          <div class="p-4">
            <div class="mb-3 flex gap-2 text-[11px] font-semibold text-white">
              <span class="rounded-full bg-emerald-500 px-2 py-0.5">Fully Funded</span>
              <span class="rounded-full bg-violet-500 px-2 py-0.5">International</span>
            </div>
            <h3 class="text-xl font-bold text-slate-800">MEXT Scholarship</h3>
            <p class="text-sm text-slate-500">Japanese Universities</p>
            <div class="mt-3 flex items-center gap-3 text-xs text-slate-500">
              <span>Japan</span>
              <span>May 20, 2026</span>
            </div>
          </div>
        </article>
      </div>

      <div class="mt-8 text-center">
        <a href="{{ route('scholarship') }}"
           class="inline-flex rounded-xl border-2 border-indigo-500 px-7 py-3 text-sm font-semibold text-indigo-600 hover:bg-indigo-50">View All Scholarships</a>
      </div>
    </section>

    <section class="mt-20 grid grid-cols-1 gap-6 md:grid-cols-3">
      <div class="rounded-2xl border border-slate-200 bg-white p-6">
        <div class="mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-600 text-white">
          <svg xmlns="http://www.w3.org/2000/svg"
               class="h-5 w-5"
               fill="none"
               viewBox="0 0 24 24"
               stroke="currentColor"
               stroke-width="2">
            <path stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M12 6.253v13m0-13C10.832 5.483 9.246 5 7.5 5S4.168 5.483 3 6.253v13C4.168 18.483 5.754 18 7.5 18s3.332.483 4.5 1.253m0-13C13.168 5.483 14.754 5 16.5 5c1.746 0 3.332.483 4.5 1.253v13C19.832 18.483 18.246 18 16.5 18c-1.746 0-3.332.483-4.5 1.253" />
          </svg>
        </div>
        <p class="text-5xl font-bold text-slate-800">1000+</p>
        <p class="mt-2 text-slate-500">Scholarships</p>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-6">
        <div class="mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-500 text-white">
          <svg xmlns="http://www.w3.org/2000/svg"
               class="h-5 w-5"
               fill="none"
               viewBox="0 0 24 24"
               stroke="currentColor"
               stroke-width="2">
            <path stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            <path stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 010 18M12 3a15 15 0 000 18" />
          </svg>
        </div>
        <p class="text-5xl font-bold text-slate-800">50+</p>
        <p class="mt-2 text-slate-500">Countries</p>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-6">
        <div class="mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-violet-600 text-white">
          <svg xmlns="http://www.w3.org/2000/svg"
               class="h-5 w-5"
               fill="none"
               viewBox="0 0 24 24"
               stroke="currentColor"
               stroke-width="2">
            <path stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <p class="text-5xl font-bold text-slate-800">Daily</p>
        <p class="mt-2 text-slate-500">Updated</p>
      </div>
    </section>
  </main>

  <section class="mt-16 bg-gradient-to-r from-indigo-600 to-violet-600 py-20 text-white">
    <div class="mx-auto max-w-4xl px-4 text-center sm:px-6 lg:px-8">
      <h2 class="text-4xl font-bold">Ready to Start Your Journey?</h2>
      <p class="mt-4 text-lg text-indigo-100">Chat with our AI assistant to find scholarships tailored to your goals and qualifications.</p>
      <a href="#"
         class="mt-8 inline-flex rounded-xl bg-white px-8 py-3 text-base font-semibold text-indigo-600 hover:bg-indigo-50">Launch Chatbot</a>
    </div>
  </section>
</body>

</html>
