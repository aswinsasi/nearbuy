<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agreement {{ $agreementNumber }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
            padding: 20px;
        }
        
        .container {
            max-width: 100%;
            margin: 0 auto;
        }
        
        /* Header */
        .header {
            text-align: center;
            border-bottom: 3px solid #2563eb;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            font-size: 24px;
            color: #1e40af;
            margin-bottom: 5px;
        }
        
        .header .subtitle {
            font-size: 14px;
            color: #6b7280;
        }
        
        .agreement-number {
            background: #dbeafe;
            color: #1e40af;
            padding: 8px 15px;
            border-radius: 5px;
            display: inline-block;
            font-weight: bold;
            font-size: 14px;
            margin-top: 10px;
        }
        
        /* Sections */
        .section {
            margin-bottom: 20px;
        }
        
        .section-title {
            background: #f3f4f6;
            padding: 8px 12px;
            font-weight: bold;
            font-size: 13px;
            color: #374151;
            border-left: 4px solid #2563eb;
            margin-bottom: 10px;
        }
        
        /* Party Details */
        .parties {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        
        .party {
            display: table-cell;
            width: 48%;
            vertical-align: top;
            padding: 15px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }
        
        .party:first-child {
            margin-right: 4%;
        }
        
        .party-label {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .party-name {
            font-size: 16px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 5px;
        }
        
        .party-phone {
            font-size: 12px;
            color: #4b5563;
        }
        
        .party-role {
            font-size: 11px;
            color: #2563eb;
            margin-top: 8px;
            padding: 4px 8px;
            background: #dbeafe;
            border-radius: 4px;
            display: inline-block;
        }
        
        /* Amount Box */
        .amount-box {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .amount-label {
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        
        .amount-value {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .amount-words {
            font-size: 11px;
            opacity: 0.85;
            font-style: italic;
        }
        
        /* Details Table */
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .details-table tr {
            border-bottom: 1px solid #e5e7eb;
        }
        
        .details-table tr:last-child {
            border-bottom: none;
        }
        
        .details-table td {
            padding: 10px;
            vertical-align: top;
        }
        
        .details-table td:first-child {
            width: 35%;
            font-weight: 600;
            color: #374151;
            background: #f9fafb;
        }
        
        .details-table td:last-child {
            color: #111827;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-confirmed {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        /* Timestamps */
        .timestamps {
            background: #f3f4f6;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .timestamp-row {
            display: table;
            width: 100%;
            margin-bottom: 8px;
        }
        
        .timestamp-row:last-child {
            margin-bottom: 0;
        }
        
        .timestamp-label {
            display: table-cell;
            width: 50%;
            font-size: 11px;
            color: #6b7280;
        }
        
        .timestamp-value {
            display: table-cell;
            width: 50%;
            font-size: 11px;
            color: #374151;
            text-align: right;
        }
        
        /* QR Code Section */
        .verification {
            text-align: center;
            padding: 20px;
            background: #f9fafb;
            border: 2px dashed #d1d5db;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .verification img {
            width: 120px;
            height: 120px;
            margin-bottom: 10px;
        }
        
        .verification-text {
            font-size: 11px;
            color: #6b7280;
            margin-bottom: 5px;
        }
        
        .verification-url {
            font-size: 10px;
            color: #2563eb;
            word-break: break-all;
        }
        
        /* Footer */
        .footer {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 10px;
            color: #9ca3af;
        }
        
        .footer-logo {
            font-weight: bold;
            color: #2563eb;
            font-size: 12px;
            margin-bottom: 5px;
        }
        
        /* Legal Notice */
        .legal-notice {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 20px;
            font-size: 10px;
            color: #92400e;
        }
        
        .legal-notice strong {
            display: block;
            margin-bottom: 5px;
        }
        
        /* Page Break */
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📋 DIGITAL AGREEMENT</h1>
            <div class="subtitle">NearBuy - WhatsApp Commerce Platform</div>
            <div class="agreement-number">{{ $agreementNumber }}</div>
        </div>
        
        <!-- Party Details -->
        <div class="section">
            <div class="section-title">PARTIES TO THIS AGREEMENT</div>
            <table style="width: 100%; border-collapse: separate; border-spacing: 10px 0;">
                <tr>
                    <td style="width: 48%; vertical-align: top; padding: 15px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
                        <div class="party-label">{{ $partyA['label'] }}</div>
                        <div class="party-name">{{ $partyA['name'] }}</div>
                        <div class="party-phone">📱 {{ $partyA['phone'] }}</div>
                        <div class="party-role">{{ $partyA['role'] }}</div>
                    </td>
                    <td style="width: 48%; vertical-align: top; padding: 15px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
                        <div class="party-label">{{ $partyB['label'] }}</div>
                        <div class="party-name">{{ $partyB['name'] }}</div>
                        <div class="party-phone">📱 {{ $partyB['phone'] }}</div>
                        <div class="party-role">{{ $partyB['role'] }}</div>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Amount -->
        <div class="amount-box">
            <div class="amount-label">TRANSACTION AMOUNT</div>
            <div class="amount-value">₹{{ $amount }}</div>
            <div class="amount-words">{{ $amountWords }}</div>
        </div>
        
        <!-- Agreement Details -->
        <div class="section">
            <div class="section-title">AGREEMENT DETAILS</div>
            <table class="details-table">
                <tr>
                    <td>Purpose</td>
                    <td>{{ $purpose }}</td>
                </tr>
                <tr>
                    <td>Description</td>
                    <td>{{ $description }}</td>
                </tr>
                <tr>
                    <td>Due Date</td>
                    <td>{{ $dueDate }}</td>
                </tr>
                <tr>
                    <td>Status</td>
                    <td>
                        <span class="status-badge status-confirmed">{{ $status }}</span>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Timestamps -->
        <div class="section">
            <div class="section-title">CONFIRMATION TIMELINE</div>
            <div class="timestamps">
                <div class="timestamp-row">
                    <div class="timestamp-label">Agreement Created</div>
                    <div class="timestamp-value">{{ $createdAt }}</div>
                </div>
                @if($creatorConfirmedAt)
                <div class="timestamp-row">
                    <div class="timestamp-label">Creator Confirmed</div>
                    <div class="timestamp-value">{{ $creatorConfirmedAt }}</div>
                </div>
                @endif
                @if($toConfirmedAt)
                <div class="timestamp-row">
                    <div class="timestamp-label">Counterparty Confirmed</div>
                    <div class="timestamp-value">{{ $toConfirmedAt }}</div>
                </div>
                @endif
                <div class="timestamp-row">
                    <div class="timestamp-label">Document Generated</div>
                    <div class="timestamp-value">{{ $generatedAt }}</div>
                </div>
            </div>
        </div>
        
        <!-- Verification QR Code -->
        <div class="verification">
            <div class="verification-text">SCAN TO VERIFY THIS AGREEMENT</div>
            @if($qrCode)
            <img src="{{ $qrCode }}" alt="Verification QR Code">
            @endif
            <div class="verification-url">{{ $verificationUrl }}</div>
        </div>
        
        <!-- Legal Notice -->
        <div class="legal-notice">
            <strong>⚠️ IMPORTANT NOTICE</strong>
            This document is a digital record of an informal agreement between the parties mentioned above. 
            It is generated based on confirmations received via WhatsApp from both parties. 
            This document serves as proof of mutual acknowledgment but may not constitute a legally binding contract. 
            For disputes exceeding ₹50,000, parties are advised to seek legal counsel.
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <div class="footer-logo">🛒 NearBuy</div>
            <div>WhatsApp-Based Local Commerce Platform</div>
            <div style="margin-top: 5px;">
                This document was automatically generated on {{ $generatedAt }}
            </div>
            <div style="margin-top: 5px;">
                Agreement ID: {{ $agreementNumber }} | Verification Token: {{ substr($agreement->verification_token ?? '', 0, 8) }}...
            </div>
        </div>
    </div>
</body>
</html>