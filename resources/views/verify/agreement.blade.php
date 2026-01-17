<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agreement Verification - NearBuy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Header -->
    <header class="bg-white shadow-sm">
        <div class="max-w-4xl mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-2">
                    <span class="text-2xl font-bold text-blue-600">Near</span>
                    <span class="text-2xl font-bold text-emerald-500">Buy</span>
                </div>
                <span class="text-sm text-gray-500">Agreement Verification</span>
            </div>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 py-8">
        @if(!$found)
            <!-- Error State -->
            <div class="bg-white rounded-xl shadow-lg p-8 text-center">
                <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-800 mb-2">Verification Failed</h1>
                <p class="text-gray-600 mb-6">{{ $error }}</p>
                
                <!-- Search Form -->
                <div class="max-w-md mx-auto">
                    <form action="{{ route('verify.search') }}" method="GET" class="flex space-x-2">
                        <input 
                            type="text" 
                            name="number" 
                            placeholder="Enter agreement number (e.g., NB-AG-2024-XXXX)"
                            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                        <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                            Search
                        </button>
                    </form>
                </div>
            </div>
        @else
            <!-- Success State -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <!-- Status Banner -->
                <div class="px-6 py-4 {{ $data['statusClass'] }} border-b">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            @if($data['isConfirmed'] || $data['isCompleted'])
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            @else
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            @endif
                            <span class="font-semibold">{{ $data['status'] }}</span>
                        </div>
                        <span class="text-sm font-mono">{{ $data['agreementNumber'] }}</span>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="p-6">
                    <!-- Verification Badge -->
                    <div class="text-center mb-8">
                        <div class="inline-flex items-center space-x-2 px-4 py-2 bg-blue-50 rounded-full">
                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                            </svg>
                            <span class="text-blue-700 font-medium">Verified Digital Agreement</span>
                        </div>
                    </div>

                    <!-- Parties -->
                    <div class="grid md:grid-cols-2 gap-6 mb-8">
                        <!-- Party A -->
                        <div class="bg-gray-50 rounded-lg p-4">
                            <div class="text-xs font-semibold text-blue-600 uppercase tracking-wide mb-2">
                                {{ $data['partyA']['label'] }}
                            </div>
                            <div class="text-lg font-semibold text-gray-800">{{ $data['partyA']['name'] }}</div>
                            <div class="text-sm text-gray-500">📱 {{ $data['partyA']['phone'] }}</div>
                        </div>

                        <!-- Party B -->
                        <div class="bg-gray-50 rounded-lg p-4">
                            <div class="text-xs font-semibold text-emerald-600 uppercase tracking-wide mb-2">
                                {{ $data['partyB']['label'] }}
                            </div>
                            <div class="text-lg font-semibold text-gray-800">{{ $data['partyB']['name'] }}</div>
                            <div class="text-sm text-gray-500">📱 {{ $data['partyB']['phone'] }}</div>
                        </div>
                    </div>

                    <!-- Amount Box -->
                    <div class="bg-gradient-to-r from-blue-600 to-blue-700 rounded-xl p-6 text-white text-center mb-8">
                        <div class="text-sm uppercase tracking-wide opacity-80 mb-1">Agreement Amount</div>
                        <div class="text-4xl font-bold mb-2">{{ $data['amount'] }}</div>
                        <div class="text-sm italic opacity-80">{{ $data['amountWords'] }}</div>
                    </div>

                    <!-- Details -->
                    <div class="space-y-4 mb-8">
                        <div class="flex justify-between py-3 border-b border-gray-100">
                            <span class="text-gray-500">Purpose</span>
                            <span class="font-medium text-gray-800">{{ $data['purpose'] }}</span>
                        </div>
                        <div class="flex justify-between py-3 border-b border-gray-100">
                            <span class="text-gray-500">Description</span>
                            <span class="font-medium text-gray-800">{{ $data['description'] }}</span>
                        </div>
                        <div class="flex justify-between py-3 border-b border-gray-100">
                            <span class="text-gray-500">Due Date</span>
                            <span class="font-medium text-gray-800">{{ $data['dueDate'] }}</span>
                        </div>
                        <div class="flex justify-between py-3 border-b border-gray-100">
                            <span class="text-gray-500">Created On</span>
                            <span class="font-medium text-gray-800">{{ $data['createdAt'] }}</span>
                        </div>
                    </div>

                    <!-- Confirmations -->
                    <div class="bg-green-50 rounded-lg p-4 mb-8">
                        <h3 class="font-semibold text-green-800 mb-3">✅ Digital Confirmations</h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-green-700">{{ $data['partyA']['name'] }}</span>
                                <span class="text-green-600 font-medium">{{ $data['creatorConfirmed'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-green-700">{{ $data['partyB']['name'] }}</span>
                                <span class="text-green-600 font-medium">{{ $data['counterpartyConfirmed'] }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Disclaimer -->
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-sm text-yellow-800">
                        <strong>⚠️ Important:</strong> This is a digital record created through NearBuy platform. 
                        Both parties confirmed the details via WhatsApp verification. This document serves as a 
                        reference and may not constitute a legally binding contract. For disputes, please consult 
                        appropriate legal authorities.
                    </div>
                </div>

                <!-- Footer -->
                <div class="bg-gray-50 px-6 py-4 text-center text-sm text-gray-500">
                    Verified on {{ now()->format('F j, Y \a\t h:i A') }} via NearBuy Digital Agreement Platform
                </div>
            </div>

            <!-- Search Another -->
            <div class="mt-6 text-center">
                <p class="text-gray-500 mb-2">Verify another agreement?</p>
                <form action="{{ route('verify.search') }}" method="GET" class="inline-flex space-x-2">
                    <input 
                        type="text" 
                        name="number" 
                        placeholder="Agreement number"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500"
                    >
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">
                        Search
                    </button>
                </form>
            </div>
        @endif
    </main>

    <!-- Footer -->
    <footer class="max-w-4xl mx-auto px-4 py-8 text-center text-sm text-gray-400">
        <p>© {{ date('Y') }} NearBuy. All rights reserved.</p>
        <p class="mt-1">For support, contact support@nearbuy.app</p>
    </footer>
</body>
</html>