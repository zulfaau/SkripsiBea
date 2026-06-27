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
          <svg x-show="!show"
               xmlns="http://www.w3.org/2000/svg"
               class="h-5 w-5"
               viewBox="0 0 20 20"
               fill="currentColor">
            <path d="M10 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z" />
            <path fill-rule="evenodd"
                  d="M.664 9.576a1.02 1.02 0 0 0 0 .848C1.61 12.383 5.485 17 10 17c4.514 0 8.39-4.617 9.336-6.576a1.02 1.02 0 0 0 0-.848C18.39 7.617 14.515 3 10 3 5.485 3 1.61 7.617.664 9.576ZM10 15c-3.238 0-6.425-3.08-7.397-5C3.575 8.08 6.762 5 10 5s6.425 3.08 7.397 5c-.972 1.92-4.159 5-7.397 5Z"
                  clip-rule="evenodd" />
          </svg>
          <svg x-show="show"
               xmlns="http://www.w3.org/2000/svg"
               class="h-5 w-5"
               viewBox="0 0 20 20"
               fill="currentColor"
               style="display: none;">
            <path d="M3.28 2.22a.75.75 0 0 0-1.06 1.06l14.5 14.5a.75.75 0 1 0 1.06-1.06l-1.745-1.745a10.029 10.029 0 0 0 3.3-3.38 1.02 1.02 0 0 0 0-.848C18.39 7.617 14.515 3 10 3c-1.92 0-3.696.812-5.12 1.942L3.28 2.22ZM11.196 6.076A3.5 3.5 0 0 0 6.077 11.2l5.12-5.124Z" />
            <path d="M12.73 14.53 10.9 12.7a2.5 2.5 0 0 1-3.4-3.4L5.63 7.43A9.97 9.97 0 0 0 1 10c.946 1.959 4.82 6.576 9.336 6.576a10.001 10.001 0 0 0 2.394-.246Z" />
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
          <svg x-show="!show"
               xmlns="http://www.w3.org/2000/svg"
               class="h-5 w-5"
               viewBox="0 0 20 20"
               fill="currentColor">
            <path d="M10 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z" />
            <path fill-rule="evenodd"
                  d="M.664 9.576a1.02 1.02 0 0 0 0 .848C1.61 12.383 5.485 17 10 17c4.514 0 8.39-4.617 9.336-6.576a1.02 1.02 0 0 0 0-.848C18.39 7.617 14.515 3 10 3 5.485 3 1.61 7.617.664 9.576ZM10 15c-3.238 0-6.425-3.08-7.397-5C3.575 8.08 6.762 5 10 5s6.425 3.08 7.397 5c-.972 1.92-4.159 5-7.397 5Z"
                  clip-rule="evenodd" />
          </svg>
          <svg x-show="show"
               xmlns="http://www.w3.org/2000/svg"
               class="h-5 w-5"
               viewBox="0 0 20 20"
               fill="currentColor"
               style="display: none;">
            <path d="M3.28 2.22a.75.75 0 0 0-1.06 1.06l14.5 14.5a.75.75 0 1 0 1.06-1.06l-1.745-1.745a10.029 10.029 0 0 0 3.3-3.38 1.02 1.02 0 0 0 0-.848C18.39 7.617 14.515 3 10 3c-1.92 0-3.696.812-5.12 1.942L3.28 2.22ZM11.196 6.076A3.5 3.5 0 0 0 6.077 11.2l5.12-5.124Z" />
            <path d="M12.73 14.53 10.9 12.7a2.5 2.5 0 0 1-3.4-3.4L5.63 7.43A9.97 9.97 0 0 0 1 10c.946 1.959 4.82 6.576 9.336 6.576a10.001 10.001 0 0 0 2.394-.246Z" />
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

  <div class="relative my-8">
    <div class="absolute inset-0 flex items-center">
      <div class="w-full border-t border-slate-200"></div>
    </div>
    <div class="relative flex justify-center text-sm">
      <span class="bg-white px-4 text-slate-500">atau</span>
    </div>
  </div>

  <p class="text-center text-sm text-slate-500">
    Sudah punya akun?
    <a href="{{ route('login') }}"
       class="font-bold text-slate-900 hover:text-indigo-600 transition-colors">Masuk</a>
  </p>
</x-guest-layout>
