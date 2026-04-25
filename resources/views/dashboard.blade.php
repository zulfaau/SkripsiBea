<x-app-layout>
    <x-slot name="header">
        <div class="mb-4">
            <h2 class="text-2xl font-bold text-gray-900 leading-tight">
                Manage Scholarships
            </h2>
            <p class="text-gray-500 text-sm mt-1">Kelola data beasiswa dan dataset untuk chatbot</p>
        </div>
    </x-slot>

    <div class="py-6 bg-[#f8fafc] min-h-screen">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <!-- Top Section: Upload & Stats -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- Upload Dataset Card -->
                <div class="col-span-1 lg:col-span-2 space-y-4">
                    <div class="bg-white rounded-2xl border border-gray-200 p-8 flex flex-col items-center justify-center text-center border-dashed">
                        <div class="w-12 h-12 mb-4 text-gray-400">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        </div>
                        <h3 class="text-gray-900 font-semibold mb-1">Upload dataset (JSON / CSV)</h3>
                        <p class="text-sm text-gray-400 mb-4">or click to browse file</p>
                        
                        <label class="bg-[#2563eb] hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg text-sm font-medium inline-flex items-center transition-colors cursor-pointer">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                            Browse Files
                            <input type="file" accept=".csv,.json" class="hidden" onchange="alert('✅ File '+this.files[0].name+' siap diunggah!')">
                        </label>
                        <p class="text-xs text-gray-400 mt-4">Supported: JSON, CSV</p>
                    </div>

                    <!-- Sync Buttons -->
                    <div class="grid grid-cols-2 gap-4">
                        <button class="bg-white border border-gray-200 rounded-xl py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 flex items-center justify-center transition-colors">
                            <svg class="w-4 h-4 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path></svg>
                            Sync with Supabase
                        </button>
                        <button class="bg-white border border-gray-200 rounded-xl py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 flex items-center justify-center transition-colors">
                            <svg class="w-4 h-4 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                            Refresh Data
                        </button>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="col-span-1 lg:col-span-1 flex flex-col gap-4">
                    <div class="bg-[#2563eb] rounded-xl p-6 text-white h-full flex flex-col justify-center">
                        <p class="text-blue-100 text-sm font-medium mb-2">Total Data</p>
                        <p class="text-4xl font-bold">4</p>
                    </div>
                    
                    <div class="bg-white rounded-xl border border-gray-200 p-5 flex items-center">
                        <div class="mr-4 text-gray-400">
                           <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400 font-medium">Last Updated</p>
                            <p class="text-gray-900 font-medium text-sm mt-0.5">25 April 2026</p>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl border border-gray-200 p-5 flex items-center">
                        <div class="mr-4 text-gray-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4M4 12c0 2.21 3.582 4 8 4s8-1.79 8-4"></path></svg>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400 font-medium">Source Type</p>
                            <p class="text-gray-900 font-medium text-sm mt-0.5">Supabase</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="bg-white border border-gray-200 rounded-xl p-3 flex flex-col md:flex-row gap-3">
                <div class="relative flex-1">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                    <input type="text" placeholder="Search by title or country..." class="pl-10 w-full border-0 focus:ring-0 text-sm text-gray-900 placeholder-gray-400 py-2 bg-transparent">
                </div>
                
                <div class="h-10 w-px bg-gray-200 hidden md:block"></div>
                
                <select class="border-0 focus:ring-0 text-sm text-gray-700 py-2 bg-transparent flex-1 appearance-none bg-no-repeat bg-right pr-8" style="background-image: url('data:image/svg+xml;utf8,<svg width=\"20\" height=\"20\" viewBox=\"0 0 24 24\" fill=\"none\" xmlns=\"http://www.w3.org/2000/svg\"><path d=\"M6 9L12 15L18 9\" stroke=\"%239CA3AF\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"/></svg>'); bg-position: right 0.5rem center; background-size: 1.25rem;">
                    <option>All Destinations</option>
                </select>

                <div class="h-10 w-px bg-gray-200 hidden md:block"></div>

                <select class="border-0 focus:ring-0 text-sm text-gray-700 py-2 bg-transparent flex-1 appearance-none bg-no-repeat bg-right pr-8" style="background-image: url('data:image/svg+xml;utf8,<svg width=\"20\" height=\"20\" viewBox=\"0 0 24 24\" fill=\"none\" xmlns=\"http://www.w3.org/2000/svg\"><path d=\"M6 9L12 15L18 9\" stroke=\"%239CA3AF\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"/></svg>'); bg-position: right 0.5rem center; background-size: 1.25rem;">
                    <option>All Degrees</option>
                </select>

                <div class="h-10 w-px bg-gray-200 hidden md:block"></div>

                <select class="border-0 focus:ring-0 text-sm text-gray-700 py-2 bg-transparent flex-1 appearance-none bg-no-repeat bg-right pr-8" style="background-image: url('data:image/svg+xml;utf8,<svg width=\"20\" height=\"20\" viewBox=\"0 0 24 24\" fill=\"none\" xmlns=\"http://www.w3.org/2000/svg\"><path d=\"M6 9L12 15L18 9\" stroke=\"%239CA3AF\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"/></svg>'); bg-position: right 0.5rem center; background-size: 1.25rem;">
                    <option>All Status</option>
                </select>
            </div>

            <!-- Table Header & Actions -->
            <div class="flex justify-between items-center pt-2" x-data="{ showModal: false }">
                <p class="text-sm text-gray-500">Showing 4 of 4 scholarships</p>
                <button @click="showModal = true" class="bg-[#2563eb] hover:bg-blue-700 text-white px-5 py-2 rounded-lg text-sm font-medium flex items-center transition-colors shadow-sm cursor-pointer">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    Add Scholarship
                </button>

                <!-- Add Scholarship Modal -->
                <div x-show="showModal" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 backdrop-blur-sm transition-opacity" x-transition.opacity>
                    <div @click.away="showModal = false" class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 p-6" x-transition.scale.origin.bottom>
                        <h3 class="text-xl font-bold text-gray-900 mb-4">Add Scholarship</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Scholarship Title</label>
                                <input type="text" class="w-full border-gray-300 rounded-lg focus:ring-[#2563eb] focus:border-[#2563eb]" placeholder="e.g. LPDP 2026">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Country</label>
                                <input type="text" class="w-full border-gray-300 rounded-lg focus:ring-[#2563eb] focus:border-[#2563eb]" placeholder="e.g. Indonesia">
                            </div>
                        </div>
                        <div class="mt-6 flex justify-end space-x-3">
                            <button @click="showModal = false" class="px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 rounded-lg">Cancel</button>
                            <button @click="showModal = false; alert('Fitur koneksi ke database beasiswa belum di-set.')" class="px-4 py-2 text-sm font-medium text-white bg-[#2563eb] hover:bg-blue-700 rounded-lg">Save Data</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="bg-white">
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Title</th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Country</th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Degree</th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Deadline</th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                <th scope="col" class="px-6 py-4 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            
                            <!-- Row 1 -->
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-900">LPDP Scholarship</div>
                                    <div class="text-xs text-gray-500 mt-1">Various Universities</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900 font-medium">Indonesia</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 inline-flex text-xs leading-4 font-semibold rounded-md bg-purple-100 text-purple-700">S2</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                                    31/8/2026
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 inline-flex text-xs leading-4 font-medium rounded-full bg-green-100 text-green-700">Active</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <button class="text-blue-500 hover:text-blue-700 p-1 mx-1.5 focus:outline-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg></button>
                                    <button class="text-red-500 hover:text-red-700 p-1 ml-1.5 focus:outline-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                                </td>
                            </tr>

                            <!-- Row 2 -->
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-900">Fulbright Scholarship</div>
                                    <div class="text-xs text-gray-500 mt-1">US Universities</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900 font-medium">United States</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 inline-flex text-xs leading-4 font-semibold rounded-md bg-purple-100 text-purple-700">S2</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                                    15/10/2026
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 inline-flex text-xs leading-4 font-medium rounded-full bg-green-100 text-green-700">Active</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <button class="text-blue-500 hover:text-blue-700 p-1 mx-1.5 focus:outline-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg></button>
                                    <button class="text-red-500 hover:text-red-700 p-1 ml-1.5 focus:outline-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                                </td>
                            </tr>

                            <!-- Row 3 -->
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-900">Chevening Scholarship</div>
                                    <div class="text-xs text-gray-500 mt-1">UK Universities</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900 font-medium">United Kingdom</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 inline-flex text-xs leading-4 font-semibold rounded-md bg-purple-100 text-purple-700">S2</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                                    1/11/2026
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 inline-flex text-xs leading-4 font-medium rounded-full bg-green-100 text-green-700">Active</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <button class="text-blue-500 hover:text-blue-700 p-1 mx-1.5 focus:outline-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg></button>
                                    <button class="text-red-500 hover:text-red-700 p-1 ml-1.5 focus:outline-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                                </td>
                            </tr>

                            <!-- Row 4 -->
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-900">DAAD Scholarship</div>
                                    <div class="text-xs text-gray-500 mt-1">German Universities</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900 font-medium">Germany</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 inline-flex text-xs leading-4 font-semibold rounded-md bg-purple-100 text-purple-700">S3</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                                    30/9/2026
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 inline-flex text-xs leading-4 font-medium rounded-full bg-gray-100 text-gray-600">Draft</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <button class="text-blue-500 hover:text-blue-700 p-1 mx-1.5 focus:outline-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg></button>
                                    <button class="text-red-500 hover:text-red-700 p-1 ml-1.5 focus:outline-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
