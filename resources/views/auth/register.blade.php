<x-guest-layout left-title="Bergabunglah Bersama Ribuan Pelajar"
                left-description="Mulai perjalananmu menemukan beasiswa yang tepat dengan bantuan teknologi AI.">
  <h1 class="text-4xl font-bold text-slate-800">Buat Akun Baru</h1>
  <p class="mt-2 text-lg text-slate-500">Mulai perjalanan menemukan beasiswa impianmu</p>

  <form method="POST"
        action="{{ route('register') }}"
        class="mt-6 space-y-5">
    @csrf

    <div>
      <label for="name"
             class="block text-sm font-semibold text-slate-700">Nama Lengkap</label>
      <input id="name"
             name="name"
             type="text"
             value="{{ old('name') }}"
             required
             autofocus
             autocomplete="name"
             placeholder="John Doe"
             class="mt-2 block w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-800 placeholder:text-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100" />
      <x-input-error :messages="$errors->get('name')"
                     class="mt-2" />
    </div>

    <div>
      <label for="email"
             class="block text-sm font-semibold text-slate-700">Email</label>
      <input id="email"
             name="email"
             type="email"
             value="{{ old('email') }}"
             required
             autocomplete="username"
             placeholder="nama@email.com"
             class="mt-2 block w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-800 placeholder:text-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100" />
      <x-input-error :messages="$errors->get('email')"
                     class="mt-2" />
    </div>

    <div x-data="{ show: false }">
      <label for="password"
             class="block text-sm font-semibold text-slate-700">Password</label>
      <div class="relative mt-2">
        <input id="password"
               name="password"
               x-bind:type="show ? 'text' : 'password'"
               required
               autocomplete="new-password"
               placeholder="Minimal 8 karakter"
               class="block w-full rounded-xl border border-slate-200 bg-white px-4 py-3 pr-12 text-slate-800 placeholder:text-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100" />
        <button type="button"
                x-on:click="show = !show"
                class="absolute inset-y-0 right-0 px-4 text-slate-400 hover:text-slate-600"
                aria-label="Toggle password visibility">
          <svg xmlns="http://www.w3.org/2000/svg"
               class="h-5 w-5"
               viewBox="0 0 20 20"
               fill="currentColor">
            <path d="M10 3c4.67 0 8.04 2.91 9.54 6.43a1.03 1.03 0 0 1 0 .74C18.04 13.69 14.67 16.6 10 16.6s-8.04-2.91-9.54-6.43a1.03 1.03 0 0 1 0-.74C1.96 5.91 5.33 3 10 3Zm0 2C6.44 5 3.83 7.1 2.5 9.8 3.83 12.5 6.44 14.6 10 14.6s6.17-2.1 7.5-4.8C16.17 7.1 13.56 5 10 5Zm0 1.6A3.2 3.2 0 1 1 6.8 9.8 3.2 3.2 0 0 1 10 6.6Zm0 2A1.2 1.2 0 1 0 11.2 9.8 1.2 1.2 0 0 0 10 8.6Z" />
          </svg>
        </button>
      </div>
      <x-input-error :messages="$errors->get('password')"
                     class="mt-2" />
    </div>

    <div x-data="{ show: false }">
      <label for="password_confirmation"
             class="block text-sm font-semibold text-slate-700">Konfirmasi Password</label>
      <div class="relative mt-2">
        <input id="password_confirmation"
               name="password_confirmation"
               x-bind:type="show ? 'text' : 'password'"
               required
               autocomplete="new-password"
               placeholder="Masukkan ulang password"
               class="block w-full rounded-xl border border-slate-200 bg-white px-4 py-3 pr-12 text-slate-800 placeholder:text-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100" />
        <button type="button"
                x-on:click="show = !show"
                class="absolute inset-y-0 right-0 px-4 text-slate-400 hover:text-slate-600"
                aria-label="Toggle password visibility">
          <svg xmlns="http://www.w3.org/2000/svg"
               class="h-5 w-5"
               viewBox="0 0 20 20"
               fill="currentColor">
            <path d="M10 3c4.67 0 8.04 2.91 9.54 6.43a1.03 1.03 0 0 1 0 .74C18.04 13.69 14.67 16.6 10 16.6s-8.04-2.91-9.54-6.43a1.03 1.03 0 0 1 0-.74C1.96 5.91 5.33 3 10 3Zm0 2C6.44 5 3.83 7.1 2.5 9.8 3.83 12.5 6.44 14.6 10 14.6s6.17-2.1 7.5-4.8C16.17 7.1 13.56 5 10 5Zm0 1.6A3.2 3.2 0 1 1 6.8 9.8 3.2 3.2 0 0 1 10 6.6Zm0 2A1.2 1.2 0 1 0 11.2 9.8 1.2 1.2 0 0 0 10 8.6Z" />
          </svg>
        </button>
      </div>
      <x-input-error :messages="$errors->get('password_confirmation')"
                     class="mt-2" />
    </div>

    <label class="flex items-start gap-3 text-sm text-slate-600">
      <input type="checkbox"
             required
             class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
      <span>Saya setuju dengan <a href="#"
           class="font-semibold text-indigo-600 hover:text-indigo-500">Syarat &amp; Ketentuan</a> yang berlaku</span>
    </label>

    <button type="submit"
            class="w-full rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-5 py-3 text-base font-semibold text-white shadow-lg shadow-indigo-200/70 hover:opacity-95 focus:outline-none focus:ring-2 focus:ring-indigo-200">
      Daftar
    </button>
  </form>

  <div class="my-6 flex items-center gap-3 text-sm text-slate-400">
    <div class="h-px flex-1 bg-slate-200"></div>
    <span>atau</span>
    <div class="h-px flex-1 bg-slate-200"></div>
  </div>

  <button type="button"
          class="w-full rounded-xl border border-slate-200 bg-white px-5 py-3 text-base font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-200 inline-flex items-center justify-center gap-3">
    <svg class="h-5 w-5"
         viewBox="0 0 24 24"
         xmlns="http://www.w3.org/2000/svg"
         aria-hidden="true">
      <path fill="#EA4335"
            d="M12 10.2v3.9h5.5c-.2 1.2-1.4 3.6-5.5 3.6-3.3 0-6-2.8-6-6.2s2.7-6.2 6-6.2c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 2.7 14.6 1.8 12 1.8 6.9 1.8 2.8 6.1 2.8 11.5S6.9 21.2 12 21.2c6.9 0 9.1-4.9 9.1-7.4 0-.5-.1-.9-.1-1.3H12Z" />
      <path fill="#34A853"
            d="M2.8 11.5c0 1.7.5 3.3 1.4 4.6l3.3-2.5c-.2-.6-.4-1.3-.4-2.1s.1-1.4.4-2.1L4.2 6.9c-.9 1.3-1.4 2.9-1.4 4.6Z" />
      <path fill="#FBBC05"
            d="M12 21.2c2.6 0 4.8-.9 6.5-2.5l-3.1-2.5c-.8.6-1.9 1-3.4 1-2.6 0-4.9-1.8-5.7-4.2L3 15.5c1.8 3.5 5.3 5.7 9 5.7Z" />
      <path fill="#4285F4"
            d="M21.1 13.8c0-.5-.1-.9-.1-1.3H12v3.9h5.5c-.3 1.3-1 2.3-2.1 3l3.1 2.5c1.8-1.7 2.6-4.1 2.6-7.1Z" />
    </svg>
    Daftar dengan Google
  </button>

  <p class="mt-6 text-center text-sm text-slate-500">
    Sudah punya akun?
    <a href="{{ route('login') }}"
       class="font-semibold text-indigo-600 hover:text-indigo-500">Masuk</a>
  </p>
</x-guest-layout>
