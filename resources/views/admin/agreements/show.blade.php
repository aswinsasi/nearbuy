@extends('admin.layouts.app')

@section('title', 'Agreement Details')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.agreements.index') }}" class="text-blue-600 hover:text-blue-700">← Back to Agreements</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Agreement Info -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">{{ $agreement->agreement_number }}</h2>
                @php
                    $statusColors = [
                        'pending' => 'bg-yellow-100 text-yellow-700',
                        'confirmed' => 'bg-green-100 text-green-700',
                        'completed' => 'bg-blue-100 text-blue-700',
                        'disputed' => 'bg-orange-100 text-orange-700',
                        'rejected' => 'bg-red-100 text-red-700',
                        'cancelled' => 'bg-gray-100 text-gray-700',
                        'expired' => 'bg-gray-100 text-gray-700',
                    ];
                    $color = $statusColors[$agreement->status->value] ?? 'bg-gray-100 text-gray-700';
                @endphp
                <span class="px-3 py-1 text-sm font-medium rounded-full {{ $color }}">
                    {{ ucfirst($agreement->status->value) }}
                </span>
            </div>

            <!-- Amount Display -->
            <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl p-6 text-white mb-6">
                <p class="text-sm opacity-80">Amount</p>
                <p class="text-3xl font-bold">₹{{ number_format($agreement->amount) }}</p>
                @if($agreement->amount_in_words)
                    <p class="text-sm opacity-80 mt-1">{{ $agreement->amount_in_words }}</p>
                @endif
            </div>

            <dl class="space-y-4">
                <div>
                    <dt class="text-sm text-gray-500">Purpose</dt>
                    <dd class="text-sm font-medium text-gray-800">{{ ucfirst($agreement->purpose->value ?? 'Other') }}</dd>
                </div>
                @if($agreement->description)
                <div>
                    <dt class="text-sm text-gray-500">Description</dt>
                    <dd class="text-sm font-medium text-gray-800">{{ $agreement->description }}</dd>
                </div>
                @endif
                <div>
                    <dt class="text-sm text-gray-500">Due Date</dt>
                    <dd class="text-sm font-medium text-gray-800">
                        {{ $agreement->due_date ? $agreement->due_date->format('F j, Y') : 'No fixed date' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">Created</dt>
                    <dd class="text-sm font-medium text-gray-800">{{ $agreement->created_at->format('F j, Y H:i') }}</dd>
                </div>
                @if($agreement->to_confirmed_at)
                <div>
                    <dt class="text-sm text-gray-500">Confirmed At</dt>
                    <dd class="text-sm font-medium text-gray-800">{{ $agreement->to_confirmed_at->format('F j, Y H:i') }}</dd>
                </div>
                @endif
            </dl>

            <!-- Actions -->
            <div class="mt-6 pt-6 border-t border-gray-200 space-y-2">
                @if($agreement->pdf_url)
                    <a href="{{ route('admin.agreements.pdf', $agreement) }}" 
                       class="block w-full py-2 px-4 text-center rounded-lg bg-green-100 text-green-700 hover:bg-green-200">
                        📄 Download PDF
                    </a>
                @endif

                @if($agreement->status->value === 'disputed')
                    <button onclick="openResolveModal()" 
                            class="w-full py-2 px-4 rounded-lg bg-orange-100 text-orange-700 hover:bg-orange-200">
                        ⚖️ Resolve Dispute
                    </button>
                @endif

                @if(!in_array($agreement->status->value, ['completed', 'cancelled']))
                    <form method="POST" action="{{ route('admin.agreements.cancel', $agreement) }}" 
                          onsubmit="return confirm('Cancel this agreement?')">
                        @csrf
                        <button type="submit" class="w-full py-2 px-4 rounded-lg bg-red-100 text-red-700 hover:bg-red-200">
                            ❌ Cancel Agreement
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <!-- Verification -->
        @if($agreement->verification_token)
        <div class="bg-white rounded-xl shadow-sm p-6 mt-6">
            <h3 class="font-semibold text-gray-800 mb-4">Verification</h3>
            <div class="text-center">
                <div class="bg-gray-100 p-4 rounded-lg mb-2">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={{ urlencode(url('/verify/' . $agreement->verification_token)) }}" 
                         alt="QR Code" class="mx-auto">
                </div>
                <p class="text-xs text-gray-500 break-all">{{ url('/verify/' . $agreement->verification_token) }}</p>
            </div>
        </div>
        @endif
    </div>

    <!-- Parties & Timeline -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Parties -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Parties</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Creator (Creditor/Debtor based on direction) -->
                <div class="p-4 border border-gray-200 rounded-lg">
                    <div class="flex items-center mb-3">
                        <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                            <span class="text-blue-600 font-medium">{{ substr($agreement->creator->name ?? 'N', 0, 1) }}</span>
                        </div>
                        <div class="ml-3">
                            <p class="font-medium text-gray-800">{{ $agreement->creator->name ?? 'Unknown' }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $agreement->direction === 'giving' ? '💸 Creditor (Giving)' : '💰 Debtor (Receiving)' }}
                            </p>
                        </div>
                    </div>
                    <div class="text-sm text-gray-500">
                        <p>📱 {{ $agreement->creator->phone ?? 'N/A' }}</p>
                        <p class="mt-1">Creator of agreement</p>
                    </div>
                    @if($agreement->creator)
                        <a href="{{ route('admin.users.show', $agreement->creator) }}" 
                           class="inline-block mt-2 text-sm text-blue-600 hover:text-blue-700">
                            View Profile →
                        </a>
                    @endif
                </div>

                <!-- Counterparty -->
                <div class="p-4 border border-gray-200 rounded-lg">
                    <div class="flex items-center mb-3">
                        <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center">
                            <span class="text-green-600 font-medium">{{ substr($agreement->to_name ?? 'N', 0, 1) }}</span>
                        </div>
                        <div class="ml-3">
                            <p class="font-medium text-gray-800">{{ $agreement->to_name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $agreement->direction === 'giving' ? '💰 Debtor (Receiving)' : '💸 Creditor (Giving)' }}
                            </p>
                        </div>
                    </div>
                    <div class="text-sm text-gray-500">
                        <p>📱 {{ $agreement->to_phone }}</p>
                        <p class="mt-1">
                            @if($agreement->counterpartyUser)
                                <span class="text-green-600">Registered User</span>
                            @else
                                <span class="text-yellow-600">Not Registered</span>
                            @endif
                        </p>
                    </div>
                    @if($agreement->counterpartyUser)
                        <a href="{{ route('admin.users.show', $agreement->counterpartyUser) }}" 
                           class="inline-block mt-2 text-sm text-blue-600 hover:text-blue-700">
                            View Profile →
                        </a>
                    @endif
                </div>
            </div>
        </div>

        <!-- Timeline -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Timeline</h3>

            <div class="relative">
                <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-gray-200"></div>

                <div class="space-y-6">
                    <!-- Created -->
                    <div class="relative flex items-start ml-10">
                        <div class="absolute -left-10 w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                            <span class="text-blue-600">📝</span>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800">Agreement Created</p>
                            <p class="text-sm text-gray-500">{{ $agreement->created_at->format('M j, Y H:i') }}</p>
                            <p class="text-sm text-gray-500">By {{ $agreement->creator->name ?? 'Unknown' }}</p>
                        </div>
                    </div>

                    <!-- Creator Confirmed -->
                    @if($agreement->creator_confirmed_at)
                    <div class="relative flex items-start ml-10">
                        <div class="absolute -left-10 w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                            <span class="text-green-600">✓</span>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800">Creator Confirmed</p>
                            <p class="text-sm text-gray-500">{{ $agreement->creator_confirmed_at->format('M j, Y H:i') }}</p>
                        </div>
                    </div>
                    @endif

                    <!-- Counterparty Action -->
                    @if($agreement->to_confirmed_at)
                    <div class="relative flex items-start ml-10">
                        <div class="absolute -left-10 w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                            <span class="text-green-600">✓✓</span>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800">Counterparty Confirmed</p>
                            <p class="text-sm text-gray-500">{{ $agreement->to_confirmed_at->format('M j, Y H:i') }}</p>
                        </div>
                    </div>
                    @elseif($agreement->status->value === 'rejected')
                    <div class="relative flex items-start ml-10">
                        <div class="absolute -left-10 w-8 h-8 bg-red-100 rounded-full flex items-center justify-center">
                            <span class="text-red-600">✗</span>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800">Counterparty Rejected</p>
                            <p class="text-sm text-gray-500">Details were incorrect</p>
                        </div>
                    </div>
                    @elseif($agreement->status->value === 'disputed')
                    <div class="relative flex items-start ml-10">
                        <div class="absolute -left-10 w-8 h-8 bg-orange-100 rounded-full flex items-center justify-center">
                            <span class="text-orange-600">⚠️</span>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800">Counterparty Disputed</p>
                            <p class="text-sm text-gray-500">Claims to not know the creator</p>
                        </div>
                    </div>
                    @elseif($agreement->status->value === 'pending')
                    <div class="relative flex items-start ml-10">
                        <div class="absolute -left-10 w-8 h-8 bg-yellow-100 rounded-full flex items-center justify-center">
                            <span class="text-yellow-600">⏳</span>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800">Awaiting Confirmation</p>
                            <p class="text-sm text-gray-500">Waiting for counterparty response</p>
                        </div>
                    </div>
                    @endif

                    <!-- Completion -->
                    @if($agreement->status->value === 'completed')
                    <div class="relative flex items-start ml-10">
                        <div class="absolute -left-10 w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                            <span class="text-blue-600">🎉</span>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800">Agreement Completed</p>
                            <p class="text-sm text-gray-500">{{ $agreement->completed_at?->format('M j, Y H:i') ?? 'N/A' }}</p>
                        </div>
                    </div>
                    @endif

                    <!-- Cancellation -->
                    @if($agreement->status->value === 'cancelled')
                    <div class="relative flex items-start ml-10">
                        <div class="absolute -left-10 w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center">
                            <span class="text-gray-600">🚫</span>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800">Agreement Cancelled</p>
                            <p class="text-sm text-gray-500">{{ $agreement->cancelled_at?->format('M j, Y H:i') ?? 'N/A' }}</p>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Admin Notes -->
        @if($agreement->admin_notes)
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Admin Notes</h3>
            <p class="text-gray-600">{{ $agreement->admin_notes }}</p>
        </div>
        @endif
    </div>
</div>

<!-- Resolve Dispute Modal -->
@if($agreement->status->value === 'disputed')
<div id="resolveModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl p-6 max-w-md w-full mx-4">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Resolve Dispute</h3>
        
        <form method="POST" action="{{ route('admin.agreements.resolve', $agreement) }}">
            @csrf

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Resolution</label>
                <select name="resolution" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select resolution...</option>
                    <option value="confirm">✅ Confirm Agreement</option>
                    <option value="reject">❌ Reject Agreement</option>
                    <option value="cancel">🚫 Cancel Agreement</option>
                </select>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                <textarea name="notes" rows="3" 
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                          placeholder="Add notes about the resolution..."></textarea>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeResolveModal()" 
                        class="flex-1 py-2 px-4 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" 
                        class="flex-1 py-2 px-4 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
                    Submit
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openResolveModal() {
        document.getElementById('resolveModal').classList.remove('hidden');
        document.getElementById('resolveModal').classList.add('flex');
    }
    
    function closeResolveModal() {
        document.getElementById('resolveModal').classList.add('hidden');
        document.getElementById('resolveModal').classList.remove('flex');
    }
</script>
@endif
@endsection