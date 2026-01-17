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
            background: #fff;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px 30px;
        }

        /* Header */
        .header {
            text-align: center;
            border-bottom: 3px solid #2563eb;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .logo {
            font-size: 28px;
            font-weight: bold;
            color: #2563eb;
            letter-spacing: 2px;
        }

        .logo span {
            color: #10b981;
        }

        .tagline {
            font-size: 10px;
            color: #666;
            margin-top: 5px;
        }

        .agreement-title {
            font-size: 18px;
            font-weight: bold;
            color: #1f2937;
            margin-top: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .agreement-number {
            font-size: 14px;
            color: #2563eb;
            font-weight: bold;
            margin-top: 5px;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 10px;
        }

        .status-confirmed {
            background: #d1fae5;
            color: #059669;
        }

        .status-pending {
            background: #fef3c7;
            color: #d97706;
        }

        /* Parties Section */
        .parties {
            display: table;
            width: 100%;
            margin: 20px 0;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }

        .party {
            display: table-cell;
            width: 50%;
            padding: 15px;
            vertical-align: top;
        }

        .party:first-child {
            border-right: 1px solid #e5e7eb;
        }

        .party-label {
            font-size: 10px;
            font-weight: bold;
            color: #2563eb;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .party-name {
            font-size: 16px;
            font-weight: bold;
            color: #1f2937;
            margin-bottom: 5px;
        }

        .party-phone {
            font-size: 12px;
            color: #666;
        }

        .party-role {
            font-size: 11px;
            color: #10b981;
            margin-top: 5px;
        }

        /* Amount Box */
        .amount-box {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin: 20px 0;
        }

        .amount-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.9;
        }

        .amount-value {
            font-size: 32px;
            font-weight: bold;
            margin: 10px 0;
        }

        .amount-words {
            font-size: 11px;
            font-style: italic;
            opacity: 0.9;
        }

        /* Details Section */
        .details {
            margin: 20px 0;
        }

        .detail-row {
            display: table;
            width: 100%;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 0;
        }

        .detail-label {
            display: table-cell;
            width: 30%;
            font-weight: bold;
            color: #4b5563;
        }

        .detail-value {
            display: table-cell;
            width: 70%;
            color: #1f2937;
        }

        /* Confirmations */
        .confirmations {
            margin: 20px 0;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
        }

        .confirmations-title {
            font-weight: bold;
            color: #1f2937;
            margin-bottom: 10px;
            font-size: 13px;
        }

        .confirmation-item {
            display: table;
            width: 100%;
            padding: 5px 0;
            font-size: 11px;
        }

        .confirmation-party {
            display: table-cell;
            width: 40%;
            color: #4b5563;
        }

        .confirmation-time {
            display: table-cell;
            width: 60%;
            color: #059669;
        }

        /* QR Section */
        .qr-section {
            display: table;
            width: 100%;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px dashed #e5e7eb;
        }

        .qr-code {
            display: table-cell;
            width: 120px;
            vertical-align: middle;
        }

        .qr-code img {
            width: 100px;
            height: 100px;
        }

        .qr-info {
            display: table-cell;
            vertical-align: middle;
            padding-left: 15px;
        }

        .qr-title {
            font-weight: bold;
            color: #1f2937;
            font-size: 12px;
        }

        .qr-url {
            font-size: 10px;
            color: #2563eb;
            word-break: break-all;
            margin-top: 5px;
        }

        .qr-note {
            font-size: 10px;
            color: #666;
            margin-top: 5px;
        }

        /* Footer */
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 10px;
            color: #9ca3af;
        }

        .footer-legal {
            margin-top: 10px;
            font-size: 9px;
            line-height: 1.4;
        }

        /* Terms */
        .terms {
            margin: 20px 0;
            padding: 15px;
            background: #fefce8;
            border: 1px solid #fef08a;
            border-radius: 8px;
            font-size: 10px;
        }

        .terms-title {
            font-weight: bold;
            color: #854d0e;
            margin-bottom: 8px;
        }

        .terms-list {
            color: #713f12;
            padding-left: 15px;
        }

        .terms-list li {
            margin-bottom: 3px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo">Near<span>Buy</span></div>
            <div class="tagline">Digital Agreement Platform</div>
            <div class="agreement-title">Financial Agreement</div>
            <div class="agreement-number">{{ $agreementNumber }}</div>
            <div class="status-badge {{ $agreement->status->value === 'confirmed' ? 'status-confirmed' : 'status-pending' }}">
                {{ $status }}
            </div>
        </div>

        <!-- Parties -->
        <div class="parties">
            <div class="party">
                <div class="party-label">{{ $partyA['label'] }}</div>
                <div class="party-name">{{ $partyA['name'] }}</div>
                <div class="party-phone">📱 {{ $partyA['phone'] }}</div>
                <div class="party-role">{{ $partyA['role'] }}</div>
            </div>
            <div class="party">
                <div class="party-label">{{ $partyB['label'] }}</div>
                <div class="party-name">{{ $partyB['name'] }}</div>
                <div class="party-phone">📱 {{ $partyB['phone'] }}</div>
                <div class="party-role">{{ $partyB['role'] }}</div>
            </div>
        </div>

        <!-- Amount Box -->
        <div class="amount-box">
            <div class="amount-label">Agreement Amount</div>
            <div class="amount-value">₹{{ $amount }}</div>
            <div class="amount-words">{{ $amountWords }}</div>
        </div>

        <!-- Details -->
        <div class="details">
            <div class="detail-row">
                <div class="detail-label">📝 Purpose</div>
                <div class="detail-value">{{ $purpose }}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">📄 Description</div>
                <div class="detail-value">{{ $description }}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">📅 Due Date</div>
                <div class="detail-value">{{ $dueDate }}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">📆 Created On</div>
                <div class="detail-value">{{ $createdAt }}</div>
            </div>
        </div>

        <!-- Confirmations -->
        <div class="confirmations">
            <div class="confirmations-title">✅ Digital Confirmations</div>
            <div class="confirmation-item">
                <div class="confirmation-party">{{ $partyA['name'] }} (Creator)</div>
                <div class="confirmation-time">
                    @if($creatorConfirmedAt)
                        Confirmed: {{ $creatorConfirmedAt }}
                    @else
                        Pending
                    @endif
                </div>
            </div>
            <div class="confirmation-item">
                <div class="confirmation-party">{{ $partyB['name'] }} (Counterparty)</div>
                <div class="confirmation-time">
                    @if($counterpartyConfirmedAt)
                        Confirmed: {{ $counterpartyConfirmedAt }}
                    @else
                        Pending
                    @endif
                </div>
            </div>
        </div>

        <!-- Terms -->
        <div class="terms">
            <div class="terms-title">⚠️ Important Notice</div>
            <ul class="terms-list">
                <li>This is a digital record of the agreement between the parties mentioned above.</li>
                <li>Both parties have confirmed the details through WhatsApp verification.</li>
                <li>This document serves as a reference and may not constitute a legal contract.</li>
                <li>For disputes, please consult appropriate legal authorities.</li>
            </ul>
        </div>

        <!-- QR Code Section -->
        <div class="qr-section">
            <div class="qr-code">
                <img src="{{ $qrCode }}" alt="QR Code">
            </div>
            <div class="qr-info">
                <div class="qr-title">🔍 Verify This Agreement</div>
                <div class="qr-url">{{ $verificationUrl }}</div>
                <div class="qr-note">Scan the QR code or visit the URL to verify the authenticity of this agreement.</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div>Generated on {{ $generatedAt }} via NearBuy Digital Agreement Platform</div>
            <div class="footer-legal">
                This document is automatically generated and digitally verified. 
                The QR code above can be used to verify the authenticity of this agreement.
                For support, contact support@nearbuy.app
            </div>
        </div>
    </div>
</body>
</html>