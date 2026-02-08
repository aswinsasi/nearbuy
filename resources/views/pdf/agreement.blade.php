<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $found ? 'Verify Agreement #' . ($data['agreementNumber'] ?? '') : 'Agreement Verification' }} - NearBuy</title>
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preconnect for faster loading -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <!-- Minimal CSS - inline for fastest load -->
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 400px;
            margin: 0 auto;
        }
        
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
            color: white;
            padding: 24px 20px;
            text-align: center;
        }
        
        .header.error {
            background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
        }
        
        .header-icon {
            font-size: 48px;
            margin-bottom: 12px;
        }
        
        .header h1 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .header .number {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .content {
            padding: 20px;
        }
        
        .parties {
            text-align: center;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .parties-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .parties-names {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .parties-arrow {
            color: #9ca3af;
            margin: 0 8px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-size: 14px;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .detail-value {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
            text-align: right;
        }
        
        .amount-value {
            font-size: 24px;
            color: #059669;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .status-green { background: #d1fae5; color: #065f46; }
        .status-yellow { background: #fef3c7; color: #92400e; }
        .status-blue { background: #dbeafe; color: #1e40af; }
        .status-orange { background: #ffedd5; color: #c2410c; }
        .status-red { background: #fee2e2; color: #991b1b; }
        .status-gray { background: #f3f4f6; color: #4b5563; }
        
        .footer {
            padding: 16px 20px;
            background: #f9fafb;
            text-align: center;
            border-top: 1px solid #e5e7eb;
        }
        
        .footer-text {
            font-size: 11px;
            color: #9ca3af;
        }
        
        .footer-brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #6b7280;
        }
        
        .error-message {
            text-align: center;
            padding: 40px 20px;
        }
        
        .error-message p {
            color: #6b7280;
            margin-top: 12px;
        }
        
        /* Amount in words */
        .amount-words {
            font-size: 11px;
            color: #6b7280;
            font-weight: normal;
            margin-top: 4px;
        }
        
        /* Confirmation details */
        .confirmations {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
        }
        
        .confirmation-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        
        .confirmation-item .icon {
            color: #10b981;
        }
        
        @media (max-width: 380px) {
            body { padding: 12px; }
            .header { padding: 20px 16px; }
            .content { padding: 16px; }
            .parties-names { font-size: 16px; }
            .amount-value { font-size: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            @if($found)
                <!-- Success Header -->
                <div class="header">
                    <div class="header-icon">✅</div>
                    <h1>NearBuy Verified Agreement</h1>
                    <div class="number">#{{ $data['agreementNumber'] }}</div>
                </div>
                
                <div class="content">
                    <!-- Parties -->
                    <div class="parties">
                        <div class="parties-label">Between</div>
                        <div class="parties-names">
                            {{ $data['party1Name'] }}
                            <span class="parties-arrow">↔</span>
                            {{ $data['party2Name'] }}
                        </div>
                    </div>
                    
                    <!-- Details -->
                    <div class="detail-row">
                        <span class="detail-label">💰 Amount</span>
                        <div class="detail-value">
                            <div class="amount-value">{{ $data['amountShort'] }}</div>
                            @if($data['amountWords'])
                                <div class="amount-words">{{ $data['amountWords'] }}</div>
                            @endif
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <span class="detail-label">{{ $data['purposeIcon'] }} Purpose</span>
                        <span class="detail-value">{{ $data['purpose'] }}</span>
                    </div>
                    
                    @if($data['description'])
                    <div class="detail-row">
                        <span class="detail-label">📝 Details</span>
                        <span class="detail-value">{{ $data['description'] }}</span>
                    </div>
                    @endif
                    
                    <div class="detail-row">
                        <span class="detail-label">📅 Due Date</span>
                        <span class="detail-value">{{ $data['dueDate'] }}</span>
                    </div>
                    
                    <div class="detail-row">
                        <span class="detail-label">📊 Status</span>
                        <span class="status-badge status-{{ $data['statusColor'] }}">
                            {{ $data['statusIcon'] }} {{ $data['status'] }}
                        </span>
                    </div>
                    
                    <div class="detail-row">
                        <span class="detail-label">🗓️ Created</span>
                        <span class="detail-value">{{ $data['createdAt'] }}</span>
                    </div>
                    
                    <!-- Confirmation Details -->
                    @if($data['creatorConfirmed'] || $data['counterpartyConfirmed'])
                    <div class="confirmations">
                        @if($data['creatorConfirmed'])
                        <div class="confirmation-item">
                            <span class="icon">✓</span>
                            <span>{{ $data['partyA']['name'] }} confirmed: {{ $data['creatorConfirmed'] }}</span>
                        </div>
                        @endif
                        @if($data['counterpartyConfirmed'])
                        <div class="confirmation-item">
                            <span class="icon">✓</span>
                            <span>{{ $data['partyB']['name'] }} confirmed: {{ $data['counterpartyConfirmed'] }}</span>
                        </div>
                        @endif
                    </div>
                    @endif
                </div>
                
            @else
                <!-- Error Header -->
                <div class="header error">
                    <div class="header-icon">❌</div>
                    <h1>Verification Failed</h1>
                </div>
                
                <div class="error-message">
                    <p>{{ $error ?? 'Unable to verify this agreement.' }}</p>
                    <p style="margin-top: 20px; font-size: 13px;">
                        If you believe this is an error, please contact the agreement parties directly.
                    </p>
                </div>
            @endif
            
            <!-- Footer -->
            <div class="footer">
                <div class="footer-text">
                    Verified on {{ now()->format('M j, Y \a\t h:i A') }}
                </div>
                <div class="footer-brand">
                    🛒 NearBuy
                </div>
            </div>
        </div>
    </div>
</body>
</html>