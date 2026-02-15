<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\Enums\FlashDealStep;
use App\Models\ConversationSession;
use App\Models\Shop;
use App\Models\User;
use App\Services\FlashDeals\FlashDealService;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\FlashDealMessages;
use Illuminate\Support\Facades\Log;

/**
 * Flow handler for Flash Deal creation by shop owners.
 *
 * Flow: Title → Image → Discount → Cap → Target → Time → Schedule → Preview → Launch
 *
 * "50% off — BUT only if 30 people claim in 30 minutes!"
 * This is the MOST VIRAL feature. Time pressure + social sharing = exponential growth.
 *
 * @srs-ref FD-001 to FD-008 - Flash Deal Creation Requirements
 * @module Flash Mob Deals
 */
class FlashDealCreateFlowHandler
{
    /**
     * Flow identifier.
     */
    public const FLOW_NAME = 'flash_deal_create';

    public function __construct(
        protected WhatsAppService $whatsApp,
        protected FlashDealService $dealService
    ) {}

    /**
     * Start the flash deal creation flow.
     */
    public function start(User $user, ConversationSession $session): void
    {
        // Verify user is a shop owner
        $shop = $user->shop;

        if (!$shop) {
            $this->whatsApp->sendText(
                $user->phone,
                "⚠️ *Shop Required*\n\n" .
                "You need to register as a shop owner to create Flash Deals.\n" .
                "ഫ്ലാഷ് ഡീൽസ് ഉണ്ടാക്കാൻ ഷോപ്പ് ഓണർ ആയി രജിസ്റ്റർ ചെയ്യണം."
            );
            return;
        }

        // Initialize session
        $session->update([
            'current_flow' => self::FLOW_NAME,
            'current_step' => FlashDealStep::ASK_TITLE->value,
            'temp_data' => [
                'shop_id' => $shop->id,
            ],
        ]);

        // Send welcome message
        $message = FlashDealMessages::welcomeCreate();
        $this->whatsApp->sendText($user->phone, $message['message']);

        Log::info('Flash deal creation started', [
            'user_id' => $user->id,
            'shop_id' => $shop->id,
        ]);
    }

    /**
     * Handle incoming message during flow.
     */
    public function handle(
        User $user,
        ConversationSession $session,
        string $messageType,
        mixed $messageContent
    ): void {
        $currentStep = FlashDealStep::from($session->current_step);

        // Handle global actions first
        if ($this->handleGlobalActions($user, $session, $messageType, $messageContent)) {
            return;
        }

        // Process based on current step
        match ($currentStep) {
            FlashDealStep::ASK_TITLE => $this->handleTitle($user, $session, $messageContent),
            FlashDealStep::ASK_IMAGE => $this->handleImage($user, $session, $messageType, $messageContent),
            FlashDealStep::ASK_DISCOUNT => $this->handleDiscount($user, $session, $messageContent),
            FlashDealStep::ASK_DISCOUNT_CAP => $this->handleDiscountCap($user, $session, $messageContent),
            FlashDealStep::ASK_TARGET => $this->handleTarget($user, $session, $messageContent),
            FlashDealStep::ASK_TIME_LIMIT => $this->handleTimeLimit($user, $session, $messageContent),
            FlashDealStep::ASK_SCHEDULE => $this->handleSchedule($user, $session, $messageContent),
            FlashDealStep::ASK_CUSTOM_TIME => $this->handleCustomTime($user, $session, $messageContent),
            FlashDealStep::PREVIEW => $this->handlePreviewAction($user, $session, $messageContent),
            FlashDealStep::EDITING => $this->handleEditSelection($user, $session, $messageContent),
            default => $this->sendCurrentStepPrompt($user, $session),
        };
    }

    /**
     * Handle global actions (cancel, back, etc.)
     */
    protected function handleGlobalActions(
        User $user,
        ConversationSession $session,
        string $messageType,
        mixed $messageContent
    ): bool {
        $buttonId = $this->extractButtonId($messageType, $messageContent);

        // Cancel flow
        if ($buttonId === 'flash_cancel' || strtolower($messageContent) === 'cancel') {
            $this->cancelFlow($user, $session);
            return true;
        }

        // Go back to previous step
        if ($buttonId === 'flash_back' || strtolower($messageContent) === 'back') {
            $this->goBack($user, $session);
            return true;
        }

        return false;
    }

    /**
     * Handle title input.
     *
     * @srs-ref FD-001
     */
    protected function handleTitle(User $user, ConversationSession $session, string $input): void
    {
        $validation = $this->dealService->validateStepInput(FlashDealStep::ASK_TITLE, $input);

        if (!$validation['valid']) {
            $this->sendValidationError($user, FlashDealStep::ASK_TITLE, $validation['error']);
            return;
        }

        // Save title and move to next step
        $tempData = $session->temp_data;
        $tempData['title'] = $validation['value'];

        $session->update([
            'temp_data' => $tempData,
            'current_step' => FlashDealStep::ASK_IMAGE->value,
        ]);

        // Send next prompt
        $message = FlashDealMessages::askImage($validation['value']);
        $this->sendWithButtons($user->phone, $message);
    }

    /**
     * Handle image upload.
     *
     * @srs-ref FD-002
     */
    protected function handleImage(
        User $user,
        ConversationSession $session,
        string $messageType,
        mixed $content
    ): void {
        if ($messageType !== 'image') {
            $this->whatsApp->sendText(
                $user->phone,
                "📸 Please send an *image* for your deal.\n" .
                "ദയവായി നിങ്ങളുടെ ഡീലിന്റെ *ഇമേജ്* അയക്കുക."
            );
            return;
        }

        $validation = $this->dealService->validateStepInput(FlashDealStep::ASK_IMAGE, $content);

        if (!$validation['valid']) {
            $this->sendValidationError($user, FlashDealStep::ASK_IMAGE, $validation['error']);
            return;
        }

        try {
            // Store the image
            $tempData = $session->temp_data;
            $imageUrl = $this->dealService->storeImage($content, $tempData['shop_id']);
            $tempData['image_url'] = $imageUrl;
            $tempData['image_data'] = $content;

            $session->update([
                'temp_data' => $tempData,
                'current_step' => FlashDealStep::ASK_DISCOUNT->value,
            ]);

            // Send next prompt
            $message = FlashDealMessages::askDiscount();
            $this->sendWithButtons($user->phone, $message);

        } catch (\Exception $e) {
            Log::error('Failed to store flash deal image', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $this->whatsApp->sendText(
                $user->phone,
                "⚠️ Failed to save image. Please try again.\n" .
                "ഇമേജ് സേവ് ചെയ്യാൻ കഴിഞ്ഞില്ല. വീണ്ടും ശ്രമിക്കുക."
            );
        }
    }

    /**
     * Handle discount percentage input.
     *
     * @srs-ref FD-003
     */
    protected function handleDiscount(User $user, ConversationSession $session, string $input): void
    {
        $validation = $this->dealService->validateStepInput(FlashDealStep::ASK_DISCOUNT, $input);

        if (!$validation['valid']) {
            $this->sendValidationError($user, FlashDealStep::ASK_DISCOUNT, $validation['error']);
            return;
        }

        $tempData = $session->temp_data;
        $tempData['discount_percent'] = $validation['value'];

        $session->update([
            'temp_data' => $tempData,
            'current_step' => FlashDealStep::ASK_DISCOUNT_CAP->value,
        ]);

        $message = FlashDealMessages::askDiscountCap($validation['value']);
        $this->sendWithButtons($user->phone, $message);
    }

    /**
     * Handle discount cap input.
     *
     * @srs-ref FD-003
     */
    protected function handleDiscountCap(User $user, ConversationSession $session, mixed $input): void
    {
        $buttonId = $this->extractButtonId('button', $input);

        // Handle "No Cap" button
        if ($buttonId === 'flash_no_cap') {
            $input = '0';
        }

        $validation = $this->dealService->validateStepInput(FlashDealStep::ASK_DISCOUNT_CAP, (string) $input);

        if (!$validation['valid']) {
            $this->sendValidationError($user, FlashDealStep::ASK_DISCOUNT_CAP, $validation['error']);
            return;
        }

        $tempData = $session->temp_data;
        $tempData['max_discount_value'] = $validation['value'];

        $session->update([
            'temp_data' => $tempData,
            'current_step' => FlashDealStep::ASK_TARGET->value,
        ]);

        $message = FlashDealMessages::askTarget(
            $tempData['discount_percent'],
            $validation['value']
        );
        $this->sendTargetButtons($user->phone, $message);
    }

    /**
     * Handle target claims selection.
     *
     * @srs-ref FD-004
     */
    protected function handleTarget(User $user, ConversationSession $session, mixed $input): void
    {
        $buttonId = $this->extractButtonId('button', $input);
        $validation = $this->dealService->validateStepInput(FlashDealStep::ASK_TARGET, $buttonId ?? $input);

        if (!$validation['valid']) {
            $this->sendValidationError($user, FlashDealStep::ASK_TARGET, $validation['error']);
            return;
        }

        $tempData = $session->temp_data;
        $tempData['target_claims'] = $validation['value'];

        $session->update([
            'temp_data' => $tempData,
            'current_step' => FlashDealStep::ASK_TIME_LIMIT->value,
        ]);

        $message = FlashDealMessages::askTimeLimit($validation['value']);
        $this->sendTimeLimitButtons($user->phone, $message);
    }

    /**
     * Handle time limit selection.
     *
     * @srs-ref FD-005
     */
    protected function handleTimeLimit(User $user, ConversationSession $session, mixed $input): void
    {
        $buttonId = $this->extractButtonId('button', $input);
        $validation = $this->dealService->validateStepInput(FlashDealStep::ASK_TIME_LIMIT, $buttonId ?? $input);

        if (!$validation['valid']) {
            $this->sendValidationError($user, FlashDealStep::ASK_TIME_LIMIT, $validation['error']);
            return;
        }

        $tempData = $session->temp_data;
        $tempData['time_limit_minutes'] = $validation['value'];

        $session->update([
            'temp_data' => $tempData,
            'current_step' => FlashDealStep::ASK_SCHEDULE->value,
        ]);

        $message = FlashDealMessages::askSchedule($validation['value']);
        $this->sendScheduleButtons($user->phone, $message);
    }

    /**
     * Handle schedule selection.
     *
     * @srs-ref FD-006
     */
    protected function handleSchedule(User $user, ConversationSession $session, mixed $input): void
    {
        $buttonId = $this->extractButtonId('button', $input);
        $validation = $this->dealService->validateStepInput(FlashDealStep::ASK_SCHEDULE, $buttonId ?? $input);

        if (!$validation['valid']) {
            $this->sendValidationError($user, FlashDealStep::ASK_SCHEDULE, $validation['error']);
            return;
        }

        $tempData = $session->temp_data;
        $tempData['schedule'] = $validation['value'];

        // If custom time selected, go to custom time step
        if ($validation['value'] === 'custom') {
            $session->update([
                'temp_data' => $tempData,
                'current_step' => FlashDealStep::ASK_CUSTOM_TIME->value,
            ]);

            $message = FlashDealMessages::askCustomTime();
            $this->sendWithButtons($user->phone, $message);
            return;
        }

        // Otherwise, go directly to preview
        $session->update([
            'temp_data' => $tempData,
            'current_step' => FlashDealStep::PREVIEW->value,
        ]);

        $this->sendPreview($user, $session);
    }

    /**
     * Handle custom time input.
     *
     * @srs-ref FD-006
     */
    protected function handleCustomTime(User $user, ConversationSession $session, string $input): void
    {
        $validation = $this->dealService->validateStepInput(FlashDealStep::ASK_CUSTOM_TIME, $input);

        if (!$validation['valid']) {
            $this->sendValidationError($user, FlashDealStep::ASK_CUSTOM_TIME, $validation['error']);
            return;
        }

        $tempData = $session->temp_data;
        $tempData['scheduled_at'] = $validation['value'];

        $session->update([
            'temp_data' => $tempData,
            'current_step' => FlashDealStep::PREVIEW->value,
        ]);

        $this->sendPreview($user, $session);
    }

    /**
     * Send deal preview.
     *
     * @srs-ref FD-008
     */
    protected function sendPreview(User $user, ConversationSession $session): void
    {
        $dealData = $this->dealService->buildPreviewData($session->temp_data);
        $preview = FlashDealMessages::preview($dealData);

        // Send image first if available
        if (!empty($preview['image_url'])) {
            $this->whatsApp->sendImage(
                $user->phone,
                $preview['image_url'],
                "📸 Deal Image"
            );
        }

        // Send preview message with buttons
        $this->whatsApp->sendButtons(
            $user->phone,
            $preview['message'],
            $preview['buttons'],
            '⚡ Flash Deal Preview'
        );
    }

    /**
     * Handle action from preview screen.
     *
     * @srs-ref FD-008
     */
    protected function handlePreviewAction(User $user, ConversationSession $session, mixed $input): void
    {
        $buttonId = $this->extractButtonId('button', $input);

        match ($buttonId) {
            'flash_launch' => $this->launchDeal($user, $session),
            'flash_edit' => $this->showEditMenu($user, $session),
            'flash_cancel' => $this->cancelFlow($user, $session),
            default => $this->sendPreview($user, $session),
        };
    }

    /**
     * Launch the deal.
     */
    protected function launchDeal(User $user, ConversationSession $session): void
    {
        try {
            $shop = Shop::find($session->temp_data['shop_id']);
            $dealData = $this->dealService->buildPreviewData($session->temp_data);

            // Create the deal in database
            $deal = $this->dealService->createDeal($shop, $dealData);

            // If launching now, send notifications immediately
            if ($dealData['schedule'] === 'now') {
                $notifiedCount = $this->dealService->launchDeal($deal);
                $deal->notified_customers_count = $notifiedCount;
            }

            // Send success message
            $successMessage = FlashDealMessages::launchSuccess($deal);
            $this->whatsApp->sendButtons(
                $user->phone,
                $successMessage['message'],
                $successMessage['buttons'],
                '🎉 Deal Launched!'
            );

            // Clear session
            $session->update([
                'current_flow' => null,
                'current_step' => null,
                'temp_data' => null,
            ]);

            Log::info('Flash deal launched successfully', [
                'deal_id' => $deal->id,
                'shop_id' => $shop->id,
                'user_id' => $user->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to launch flash deal', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $this->whatsApp->sendText(
                $user->phone,
                "⚠️ *Error launching deal*\n\n" .
                "Something went wrong. Please try again.\n" .
                "എന്തോ കുഴപ്പം സംഭവിച്ചു. വീണ്ടും ശ്രമിക്കുക."
            );
        }
    }

    /**
     * Show edit menu.
     */
    protected function showEditMenu(User $user, ConversationSession $session): void
    {
        $session->update([
            'current_step' => FlashDealStep::EDITING->value,
        ]);

        $editMenu = FlashDealMessages::editMenu();

        // Send list message for edit options
        $this->whatsApp->sendList(
            $user->phone,
            $editMenu['message'],
            $editMenu['list']['button_text'],
            $editMenu['list']['sections']
        );
    }

    /**
     * Handle edit field selection.
     */
    protected function handleEditSelection(User $user, ConversationSession $session, mixed $input): void
    {
        $selection = $this->extractListSelection($input);
        $editableFields = FlashDealStep::editableFields();

        // Extract field from selection (e.g., "edit_title" -> "title")
        $field = str_replace('edit_', '', $selection);

        if (isset($editableFields[$field])) {
            $targetStep = $editableFields[$field];

            $session->update([
                'current_step' => $targetStep->value,
                'editing_field' => $field,
            ]);

            $this->sendCurrentStepPrompt($user, $session);
        } else {
            // If back to preview
            if ($selection === 'flash_preview') {
                $session->update([
                    'current_step' => FlashDealStep::PREVIEW->value,
                ]);
                $this->sendPreview($user, $session);
            }
        }
    }

    /**
     * Go back to previous step.
     */
    protected function goBack(User $user, ConversationSession $session): void
    {
        $currentStep = FlashDealStep::from($session->current_step);
        $previousStep = $currentStep->previous();

        if ($previousStep) {
            $session->update([
                'current_step' => $previousStep->value,
            ]);

            $this->sendCurrentStepPrompt($user, $session);
        } else {
            $this->cancelFlow($user, $session);
        }
    }

    /**
     * Cancel the flow.
     */
    protected function cancelFlow(User $user, ConversationSession $session): void
    {
        $session->update([
            'current_flow' => null,
            'current_step' => null,
            'temp_data' => null,
        ]);

        $cancelled = FlashDealMessages::cancelled();
        $this->whatsApp->sendButtons(
            $user->phone,
            $cancelled['message'],
            $cancelled['buttons']
        );
    }

    /**
     * Send current step prompt.
     */
    protected function sendCurrentStepPrompt(User $user, ConversationSession $session): void
    {
        $currentStep = FlashDealStep::from($session->current_step);
        $tempData = $session->temp_data;

        match ($currentStep) {
            FlashDealStep::ASK_TITLE => $this->sendWithButtons(
                $user->phone,
                FlashDealMessages::welcomeCreate()
            ),
            FlashDealStep::ASK_IMAGE => $this->sendWithButtons(
                $user->phone,
                FlashDealMessages::askImage($tempData['title'] ?? '')
            ),
            FlashDealStep::ASK_DISCOUNT => $this->sendWithButtons(
                $user->phone,
                FlashDealMessages::askDiscount()
            ),
            FlashDealStep::ASK_DISCOUNT_CAP => $this->sendWithButtons(
                $user->phone,
                FlashDealMessages::askDiscountCap($tempData['discount_percent'] ?? 0)
            ),
            FlashDealStep::ASK_TARGET => $this->sendTargetButtons(
                $user->phone,
                FlashDealMessages::askTarget(
                    $tempData['discount_percent'] ?? 0,
                    $tempData['max_discount_value'] ?? null
                )
            ),
            FlashDealStep::ASK_TIME_LIMIT => $this->sendTimeLimitButtons(
                $user->phone,
                FlashDealMessages::askTimeLimit($tempData['target_claims'] ?? 0)
            ),
            FlashDealStep::ASK_SCHEDULE => $this->sendScheduleButtons(
                $user->phone,
                FlashDealMessages::askSchedule($tempData['time_limit_minutes'] ?? 0)
            ),
            FlashDealStep::ASK_CUSTOM_TIME => $this->sendWithButtons(
                $user->phone,
                FlashDealMessages::askCustomTime()
            ),
            FlashDealStep::PREVIEW => $this->sendPreview($user, $session),
            default => null,
        };
    }

    /**
     * Send validation error message.
     */
    protected function sendValidationError(User $user, FlashDealStep $step, string $error): void
    {
        $errorMessage = FlashDealMessages::validationError($step, $error);
        $this->sendWithButtons($user->phone, $errorMessage);
    }

    /**
     * Send message with buttons.
     */
    protected function sendWithButtons(string $phone, array $messageData): void
    {
        if (isset($messageData['buttons'])) {
            $this->whatsApp->sendButtons(
                $phone,
                $messageData['message'],
                $messageData['buttons']
            );
        } else {
            $this->whatsApp->sendText($phone, $messageData['message']);
        }
    }

    /**
     * Send target selection buttons (needs two messages for 4 options).
     */
    protected function sendTargetButtons(string $phone, array $messageData): void
    {
        // Send main message with first 3 buttons
        $this->whatsApp->sendButtons(
            $phone,
            $messageData['message'],
            $messageData['buttons']
        );

        // Send extra option
        if (!empty($messageData['extra_buttons'])) {
            $this->whatsApp->sendButtons(
                $phone,
                "Or choose:",
                $messageData['extra_buttons']
            );
        }
    }

    /**
     * Send time limit selection buttons.
     */
    protected function sendTimeLimitButtons(string $phone, array $messageData): void
    {
        $this->whatsApp->sendButtons(
            $phone,
            $messageData['message'],
            $messageData['buttons']
        );

        if (!empty($messageData['extra_buttons'])) {
            $this->whatsApp->sendButtons(
                $phone,
                "Or choose:",
                $messageData['extra_buttons']
            );
        }
    }

    /**
     * Send schedule selection buttons.
     */
    protected function sendScheduleButtons(string $phone, array $messageData): void
    {
        $this->whatsApp->sendButtons(
            $phone,
            $messageData['message'],
            $messageData['buttons']
        );

        if (!empty($messageData['extra_buttons'])) {
            $this->whatsApp->sendButtons(
                $phone,
                "Or choose:",
                $messageData['extra_buttons']
            );
        }
    }

    /**
     * Extract button ID from message content.
     */
    protected function extractButtonId(string $messageType, mixed $content): ?string
    {
        if ($messageType === 'button' || $messageType === 'interactive.button_reply') {
            return is_array($content) ? ($content['id'] ?? null) : $content;
        }

        return null;
    }

    /**
     * Extract list selection from message content.
     */
    protected function extractListSelection(mixed $content): ?string
    {
        if (is_array($content)) {
            return $content['id'] ?? $content['title'] ?? null;
        }

        return $content;
    }

    /**
     * Check if this handler can handle the current session.
     */
    public static function canHandle(ConversationSession $session): bool
    {
        return $session->current_flow === self::FLOW_NAME;
    }
}