/**
 * Deep-link opener for custom_chat (reference / patch helper).
 * Opens chat from ?chatId= / ?dialogId= / ?dealId= / ?leadId=
 * Actual logic lives in index.php openFromQuery + resolveChatItemForCrmEntity.
 */
(async function openFromQuery() {
	const params = new URLSearchParams(window.location.search);
	const chatIdParam = params.get('chatId');
	const dialogIdParam = params.get('dialogId');
	const dealIdParam = params.get('dealId') || params.get('DEAL_ID');
	const leadIdParam = params.get('leadId') || params.get('LEAD_ID');
	if (!chatIdParam && !dialogIdParam && !dealIdParam && !leadIdParam) return;

	// Implemented in index.php — see resolveChatItemForCrmEntity / openFromQuery
	console.info('WA CC deeplink', { chatIdParam, dialogIdParam, dealIdParam, leadIdParam });
})();
