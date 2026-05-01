<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId    = intval($_SESSION['user_id']);
$userName  = $_SESSION['user_name'] ?? 'User';
$userRole  = $_SESSION['role'] ?? 'buyer';

// Determine initial tab / active conversation
$listingId      = intval($_GET['listing_id'] ?? 0);
$directSellerId = intval($_GET['direct_seller_id'] ?? 0);
$openConvId     = intval($_GET['conv'] ?? 0);
$initialTab     = $_GET['tab'] ?? '';

if (!in_array($initialTab, ['buying', 'selling', 'direct'])) {
    if ($directSellerId > 0) $initialTab = 'direct';
    elseif ($listingId > 0) $initialTab = 'buying';
    else $initialTab = 'buying';
}

include 'includes/header.php';
?>

<link rel="stylesheet" href="assets/css/chat_v2.css?v=<?php echo time(); ?>">

<div class="chat-v2-wrap">
    <div class="chat-v2-heading" data-aos="fade-up">
        <div>
            <h1><i class="fas fa-comments"></i> Messages</h1>
            <p>Your conversations — buying, selling, and direct chats</p>
        </div>
    </div>

    <div class="chat-v2-shell" id="chatShell">

        <!-- ═══ LEFT SIDEBAR ═══ -->
        <div class="chat-v2-sidebar">
            <div class="cv2-search">
                <div class="cv2-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" class="cv2-search-input" id="convSearchInput" placeholder="Search conversations..." oninput="filterConversations(this.value)">
                </div>
            </div>
            <div class="chat-v2-tabs">
                <button class="chat-v2-tab <?php echo $initialTab==='buying'  ? 'active' : ''; ?>" data-tab="buying"  onclick="switchTab('buying', this)">
                    <i class="fas fa-shopping-bag"></i> Buying
                </button>
                <button class="chat-v2-tab <?php echo $initialTab==='selling' ? 'active' : ''; ?>" data-tab="selling" onclick="switchTab('selling', this)">
                    <i class="fas fa-store"></i> Selling
                </button>
                <button class="chat-v2-tab <?php echo $initialTab==='direct'  ? 'active' : ''; ?>" data-tab="direct"  onclick="switchTab('direct', this)">
                    <i class="fas fa-user-friends"></i> Direct
                </button>
            </div>
            <div class="chat-v2-list" id="convList">
                <div class="cv2-empty"><i class="fas fa-spinner fa-spin"></i><p>Loading...</p></div>
            </div>
        </div>

        <!-- ═══ RIGHT CHAT PANE ═══ -->
        <div class="chat-v2-pane" id="chatPane">
            <div class="cv2-placeholder">
                <i class="fas fa-comments"></i>
                <p style="font-size:0.95rem;font-weight:600;">Select a conversation</p>
                <p style="font-size:0.8rem;">Pick a chat from the sidebar to start messaging</p>
            </div>
        </div>

    </div>
</div>

<!-- Offer Modal -->
<div class="cv2-modal-overlay" id="offerModal" onclick="if(event.target===this)closeOfferModal()">
    <div class="cv2-modal">
        <h3><i class="fas fa-tag" style="color:#ffb74d;"></i> Make an Offer</h3>
        <p class="modal-sub">Enter your price and an optional note</p>
        <div class="offer-input-wrap">
            <span class="cur">Rs.</span>
            <input type="number" id="offerAmountInput" placeholder="Enter amount" min="1" step="1">
        </div>
        <textarea id="offerNoteInput" placeholder="Add a note (optional)..." rows="2"></textarea>
        <div class="cv2-modal-btns">
            <button class="btn-cancel" onclick="closeOfferModal()">Cancel</button>
            <button class="btn-submit" onclick="submitOffer()"><i class="fas fa-paper-plane"></i> Send Offer</button>
        </div>
    </div>
</div>

<!-- Report Modal -->
<div class="cv2-modal-overlay cv2-report-modal" id="reportModal" onclick="if(event.target===this)closeReportModal()">
    <div class="cv2-modal">
        <h3><i class="fas fa-flag" style="color:#e53935;"></i> Report Chat</h3>
        <p class="modal-sub">Describe the issue. Admin will review your report.</p>
        <textarea id="reportReasonInput" placeholder="What went wrong? Be specific..." rows="4"></textarea>
        <div class="cv2-modal-btns">
            <button class="btn-cancel" onclick="closeReportModal()">Cancel</button>
            <button class="btn-submit" onclick="submitReport()"><i class="fas fa-flag"></i> Submit Report</button>
        </div>
    </div>
</div>

<!-- Image Lightbox -->
<div class="cv2-lightbox" id="imageLightbox" onclick="closeLightbox()">
    <button class="lb-close" onclick="closeLightbox()"><i class="fas fa-times"></i></button>
    <img id="lightboxImg" src="" alt="Full image">
</div>

<script>
(function(){
    /* ═══════ STATE ═══════ */
    const CURRENT_USER = <?php echo $userId; ?>;
    let activeTab      = '<?php echo $initialTab; ?>';
    let activeConvId   = 0;
    let lastMsgId      = 0;
    let allConvs       = [];
    let convMeta       = null;  // current conv metadata
    let pollInterval   = null;
    let heartbeatInt   = null;
    let expiresAt      = null;  // Date obj for timer
    const CURRENT_AVATAR = '<?php echo htmlspecialchars($_SESSION["avatar"] ?? "", ENT_QUOTES); ?>';
    const CURRENT_NAME   = '<?php echo htmlspecialchars($_SESSION["name"] ?? "You", ENT_QUOTES); ?>';

    const convList = document.getElementById('convList');
    const chatPane = document.getElementById('chatPane');

    /* ═══════ HELPERS ═══════ */
    function esc(s) { if(!s) return ''; const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

    function timeAgo(d) {
        if (!d) return '';
        const s = Math.floor((Date.now() - new Date(d).getTime()) / 1000);
        if (s < 60)    return 'Just now';
        if (s < 3600)  return Math.floor(s/60) + 'm';
        if (s < 86400) return Math.floor(s/3600) + 'h';
        return Math.floor(s/86400) + 'd';
    }

    function fmtTime(d) {
        const dt = new Date(d);
        return dt.getHours().toString().padStart(2,'0') + ':' + dt.getMinutes().toString().padStart(2,'0');
    }

    function fmtDate(d) {
        const dt = new Date(d);
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        const days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        return dt.getFullYear() + ' ' + months[dt.getMonth()] + ' ' + dt.getDate() + ', ' + days[dt.getDay()];
    }

    function lastSeenText(lastSeen) {
        if (!lastSeen) return 'Offline';
        const s = Math.floor((Date.now() - new Date(lastSeen).getTime()) / 1000);
        if (s < 60) return 'Online';
        if (s < 3600) return 'Last seen ' + Math.floor(s/60) + ' min(s) ago';
        if (s < 86400) return 'Last seen ' + Math.floor(s/3600) + ' hour(s) ago';
        return 'Last seen ' + Math.floor(s/86400) + ' day(s) ago';
    }

    function profileUrl(uid) { return 'seller.php?id=' + uid; }

    /* ═══════ HEARTBEAT ═══════ */
    function sendHeartbeat() {
        const fd = new FormData();
        fd.append('action', 'heartbeat');
        fetch('api/chat_v2.php', { method: 'POST', body: fd }).catch(()=>{});
    }
    sendHeartbeat();
    heartbeatInt = setInterval(sendHeartbeat, 30000);

    /* ═══════ TAB SWITCHING ═══════ */
    window.switchTab = function(tab, btn) {
        activeTab = tab;
        document.querySelectorAll('.chat-v2-tab').forEach(t => t.classList.remove('active'));
        if (btn) btn.classList.add('active');
        else document.querySelector('.chat-v2-tab[data-tab="'+tab+'"]')?.classList.add('active');
        loadConversations();
    };

    /* ═══════ LOAD CONVERSATIONS ═══════ */
    function loadConversations() {
        fetch('api/chat_v2.php?action=list&type=' + activeTab)
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.conversations.length) {
                    let emptyMsg = 'No conversations yet';
                    if (activeTab === 'buying')  emptyMsg = 'No buying conversations yet. Negotiate on a listing to start.';
                    if (activeTab === 'selling') emptyMsg = 'No buyers have contacted you yet.';
                    if (activeTab === 'direct')  emptyMsg = 'No direct chats. Visit a seller profile to start chatting.';
                    convList.innerHTML = `<div class="cv2-empty"><i class="fas fa-inbox"></i><p>${emptyMsg}</p></div>`;
                    allConvs = [];
                    return;
                }

                allConvs = data.conversations;
                let html = '';
                data.conversations.forEach(c => {
                    const isAct = activeConvId === parseInt(c.id);
                    const unread = parseInt(c.unread_count) > 0;

                    // Partner info
                    let partnerName, partnerAvatar, partnerLS, partnerId;
                    if (activeTab === 'selling') {
                        partnerName = c.buyer_name; partnerAvatar = c.buyer_avatar; partnerLS = c.buyer_last_seen; partnerId = c.buyer_id;
                    } else {
                        partnerName = c.seller_name; partnerAvatar = c.seller_avatar; partnerLS = c.seller_last_seen; partnerId = c.seller_id;
                    }

                    // For direct chats: partner could be either buyer or seller
                    if (activeTab === 'direct') {
                        if (parseInt(c.buyer_id) === CURRENT_USER) {
                            partnerName = c.seller_name; partnerAvatar = c.seller_avatar; partnerLS = c.seller_last_seen; partnerId = c.seller_id;
                        } else {
                            partnerName = c.buyer_name; partnerAvatar = c.buyer_avatar; partnerLS = c.buyer_last_seen; partnerId = c.buyer_id;
                        }
                    }

                    const isOnline = partnerLS && (Date.now() - new Date(partnerLS).getTime()) / 1000 <= 60;

                    const avatarInner = partnerAvatar
                        ? `<img src="${esc(partnerAvatar)}" alt="">`
                        : esc((partnerName || 'U').charAt(0).toUpperCase());

                    const listingLine = c.listing_title
                        ? `<div class="cv2-listing">${esc(c.listing_title)}</div>`
                        : '';

                    let statusPill = '';
                    if (c.type !== 'direct' && c.status !== 'active') {
                        statusPill = `<span class="cv2-status-pill s-${esc(c.status)}">${esc(c.status)}</span>`;
                    }

                    html += `
                    <div class="cv2-item ${isAct?'active':''} ${unread?'has-unread':''}" data-conv-id="${c.id}" onclick="selectConv(${c.id})">
                        <div class="cv2-avatar-wrap">
                            <div class="cv2-avatar" onclick="event.stopPropagation(); window.location='${profileUrl(partnerId)}'">${avatarInner}</div>
                            <span class="cv2-presence-dot ${isOnline?'online':''}"></span>
                        </div>
                        <div class="cv2-body">
                            <div class="cv2-top">
                                <span class="cv2-name" onclick="event.stopPropagation(); window.location='${profileUrl(partnerId)}'">${esc(partnerName)}</span>
                                <span class="cv2-time">${timeAgo(c.last_message_at)}</span>
                            </div>
                            ${listingLine}
                            <div class="cv2-preview">${statusPill}${esc(c.last_message) || '...'}</div>
                        </div>
                        ${unread ? '<span class="cv2-unread-dot"></span>' : ''}
                    </div>`;
                });
                convList.innerHTML = html;
            })
            .catch(() => {
                convList.innerHTML = '<div class="cv2-empty"><i class="fas fa-exclamation-triangle"></i><p>Failed to load.</p></div>';
            });
    }

    /* ═══════ SELECT CONVERSATION ═══════ */
    window.selectConv = function(convId) {
        activeConvId = convId;
        lastMsgId = 0;
        convMeta = null;

        // Mobile: slide chat pane in
        document.getElementById('chatShell')?.classList.add('chat-open');

        // Highlight
        document.querySelectorAll('.cv2-item').forEach(el =>
            el.classList.toggle('active', parseInt(el.dataset.convId) === convId)
        );

        // Show loading
        chatPane.innerHTML = '<div class="cv2-placeholder"><i class="fas fa-spinner fa-spin"></i><p>Loading chat...</p></div>';

        // Fetch initial messages
        fetchMessages(true);
    };

    /* ═══════ FETCH MESSAGES ═══════ */
    function fetchMessages(isInitial) {
        if (!activeConvId) return;

        fetch(`api/chat_v2.php?action=fetch&conversation_id=${activeConvId}&after_id=${lastMsgId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;

                convMeta = data.conversation;
                const msgs = data.messages;
                const presence = data.partner_presence;

                if (isInitial) {
                    renderChatUI(convMeta, presence, msgs);
                } else {
                    // Append new messages
                    if (msgs.length > 0) {
                        const msgsDiv = document.getElementById('cv2Messages');
                        if (msgsDiv) {
                            // Remove optimistic placeholders — real data has arrived
                            msgsDiv.querySelectorAll('[data-optimistic]').forEach(el => el.remove());

                            let lastDate = msgsDiv.dataset.lastDate || '';
                            msgs.forEach(m => {
                                const msgDate = fmtDate(m.created_at);
                                if (msgDate !== lastDate) {
                                    msgsDiv.insertAdjacentHTML('beforeend', `<div class="cv2-date-sep">${esc(msgDate)}</div>`);
                                    lastDate = msgDate;
                                }
                                msgsDiv.insertAdjacentHTML('beforeend', renderMessage(m));
                                lastMsgId = Math.max(lastMsgId, parseInt(m.id));
                            });
                            msgsDiv.dataset.lastDate = lastDate;
                            msgsDiv.scrollTop = msgsDiv.scrollHeight;
                        }
                    }
                    // Update presence
                    updatePresence(presence);
                }
            })
            .catch(() => {});
    }

    /* ═══════ RENDER FULL CHAT UI ═══════ */
    function renderChatUI(conv, presence, msgs) {
        // Determine partner
        let partnerName, partnerAvatar, partnerId;
        if (activeTab === 'selling') {
            partnerName = conv.buyer_name; partnerAvatar = conv.buyer_avatar; partnerId = conv.buyer_uid || conv.buyer_id;
        } else if (activeTab === 'direct') {
            if (parseInt(conv.buyer_id) === CURRENT_USER) {
                partnerName = conv.seller_name; partnerAvatar = conv.seller_avatar; partnerId = conv.seller_uid || conv.seller_id;
            } else {
                partnerName = conv.buyer_name; partnerAvatar = conv.buyer_avatar; partnerId = conv.buyer_uid || conv.buyer_id;
            }
        } else {
            partnerName = conv.seller_name; partnerAvatar = conv.seller_avatar; partnerId = conv.seller_uid || conv.seller_id;
        }

        const isOnline = presence.online;
        const presenceCls = isOnline ? 'online' : 'offline';
        const presenceStr = isOnline ? 'Online' : lastSeenText(presence.last_seen);

        const avatarInner = partnerAvatar
            ? `<img src="${esc(partnerAvatar)}" alt="">`
            : esc((partnerName || 'U').charAt(0).toUpperCase());

        // Header
        let headerHtml = `
        <div class="cv2-chat-header">
            <button class="cv2-mobile-back" onclick="closeMobileChat()" title="Back"><i class="fas fa-arrow-left"></i></button>
            <div class="cv2-header-avatar" onclick="window.location='${profileUrl(partnerId)}'">${avatarInner}</div>
            <div class="cv2-header-info">
                <h3 class="cv2-header-name">
                    <a href="${profileUrl(partnerId)}">${esc(partnerName)}</a>
                </h3>
                <div class="cv2-header-presence ${presenceCls}" id="cv2Presence">
                    <span class="dot"></span> <span id="cv2PresenceText">${presenceStr}</span>
                </div>
            </div>
            <div class="cv2-header-actions">
                <button class="cv2-header-btn danger" title="Report chat" onclick="openReportModal()"><i class="fas fa-flag"></i></button>
            </div>
        </div>`;

        // Listing strip
        let listingHtml = '';
        if (conv.listing_title) {
            const listImg = conv.listing_image
                ? `<img src="${esc(conv.listing_image)}" alt="">`
                : '<i class="fas fa-car-side"></i>';

            const statusMap = {
                'active': '<span class="cv2-listing-status" style="background:rgba(76,175,80,0.15);color:#81c784;">Active</span>',
                'accepted': '<span class="cv2-listing-status" style="background:rgba(33,150,243,0.15);color:#64b5f6;">Accepted</span>',
                'rejected': '<span class="cv2-listing-status" style="background:rgba(229,57,53,0.15);color:#ef5350;">Rejected</span>',
                'expired': '<span class="cv2-listing-status" style="background:rgba(255,255,255,0.06);color:#888;">Expired</span>',
            };

            listingHtml = `<div class="cv2-listing-strip">
                <div class="cv2-listing-thumb">${listImg}</div>
                <div class="cv2-listing-info">
                    <h4>${esc(conv.listing_title)}</h4>
                    <div class="cv2-listing-price">Rs.${parseInt(conv.listing_price).toLocaleString()}</div>
                </div>
                ${statusMap[conv.status] || ''}
            </div>`;
        }

        // Messages
        let msgsHtml = '';
        let lastDate = '';
        msgs.forEach(m => {
            const msgDate = fmtDate(m.created_at);
            if (msgDate !== lastDate) {
                msgsHtml += `<div class="cv2-date-sep">${esc(msgDate)}</div>`;
                lastDate = msgDate;
            }
            msgsHtml += renderMessage(m);
            lastMsgId = Math.max(lastMsgId, parseInt(m.id));
        });

        // Status bar (for non-active negotiations)
        let statusBarHtml = '';
        if (conv.type !== 'direct' && conv.status !== 'active') {
            const sMap = {
                'accepted': { cls: 'accepted', icon: 'check-circle', text: 'Offer accepted!', showCheckout: true },
                'rejected': { cls: 'rejected', icon: 'times-circle', text: 'Offer was declined', showCheckout: false },
                'expired':  { cls: 'expired',  icon: 'hourglass-end', text: 'Negotiation expired', showCheckout: false },
            };
            const s = sMap[conv.status] || sMap['expired'];
            let checkoutLink = '';
            if (s.showCheckout && parseInt(conv.buyer_id) === CURRENT_USER && !conv.payment_proof_at) {
                checkoutLink = `<a href="checkout.php?nego_id=${conv.id}"><i class="fas fa-shopping-cart"></i> Checkout</a>`;
            }
            statusBarHtml = `<div class="cv2-status-bar ${s.cls}" id="cv2StatusBar"><i class="fas fa-${s.icon}"></i> ${s.text} ${checkoutLink}</div>`;
        }

        // Input bar
        let inputHtml = '';
        let canChat = conv.type === 'direct' || conv.status === 'active' || (conv.status === 'accepted' && !conv.is_payment_expired);
        let canOffer = conv.type !== 'direct' && conv.status === 'active';

        // Check if item is sold out globally
        const isSoldOutGlobally = (conv.listing_status === 'sold' || parseInt(conv.listing_stock) <= 0);
        let soldOutDisclaimer = false;

        // If it's a negotiation and it's active (not accepted yet), but the item just sold out elsewhere
        if (conv.type !== 'direct' && conv.status === 'active' && isSoldOutGlobally) {
            canChat = false;
            canOffer = false;
            soldOutDisclaimer = true;
        }

        // Disclaimer logic
        let disclaimerHtml = '';
        if (conv.type !== 'direct' && (conv.status === 'active' || conv.status === 'accepted')) {
            disclaimerHtml = `<div style="text-align:center;font-size:0.75rem;color:var(--admin-muted, #94a3b8);padding:8px;background:rgba(255,255,255,0.015);border-top:1px solid rgba(255,255,255,0.05);border-bottom:1px solid rgba(255,255,255,0.05);">⚠️ Disclaimer: Negotiation chats will automatically lock 2 months after payment proof is uploaded.</div>`;
        }

        if (soldOutDisclaimer) {
            inputHtml = `<div class="cv2-status-bar expired" style="text-align:center;justify-content:center;font-size:0.85rem;"><i class="fas fa-lock"></i> Item sold out. This conversation is locked.</div>`;
        } else if (canChat) {
            inputHtml = `
            ${disclaimerHtml}
            <div class="cv2-upload-preview" id="uploadPreview">
                <img id="uploadPreviewImg" src="" alt="">
                <span class="up-name" id="uploadPreviewName"></span>
                <button class="up-cancel" onclick="cancelUpload()"><i class="fas fa-times"></i></button>
            </div>
            <div class="cv2-input-bar" id="cv2InputBar">
                <button class="cv2-input-btn cv2-btn-img" title="Send image">
                    <i class="fas fa-camera"></i>
                    <input type="file" accept="image/*" onchange="handleImageSelect(this)" id="imgFileInput">
                </button>
                <button class="cv2-input-btn cv2-btn-chat-icon" id="cv2ChatToggle" title="Type a message" onclick="toggleChatInput()">
                    <i class="fas fa-comment-dots"></i>
                </button>
                ${canOffer ? '<button class="cv2-input-btn cv2-btn-offer cv2-collapsible-btn" onclick="openOfferModal()" title="Make offer"><span>Put offer</span> <i class="fas fa-tag"></i></button>' : ''}
                <input type="text" id="cv2MsgInput" class="cv2-msg-input-hidden" placeholder="Enter message here..." autocomplete="off" onkeypress="if(event.key==='Enter')sendTextMsg()" onblur="collapseChatInput()">
                <button class="cv2-input-btn cv2-btn-send" id="cv2SendBtn" onclick="sendTextMsg()" title="Send"><i class="fas fa-paper-plane"></i></button>
            </div>`;
        } else if (conv.type !== 'direct' && conv.is_payment_expired) {
            inputHtml = `<div class="cv2-status-bar expired" style="text-align:center;justify-content:center;font-size:0.85rem;"><i class="fas fa-lock"></i> Chat locked: 2 months have passed since payment proof.</div>`;
        }

        chatPane.innerHTML = headerHtml + listingHtml +
            `<div class="cv2-messages" id="cv2Messages" data-last-date="${esc(lastDate)}">${msgsHtml}</div>` +
            statusBarHtml + inputHtml;

        // Scroll to bottom
        const msgsDiv = document.getElementById('cv2Messages');
        if (msgsDiv) msgsDiv.scrollTop = msgsDiv.scrollHeight;

        // Don't auto-focus input — it starts collapsed
    }

    /* ═══════ RENDER MESSAGE ═══════ */
    function renderMessage(m) {
        const isSelf = parseInt(m.sender_id) === CURRENT_USER;
        const isSystem = parseInt(m.sender_id) === 0 || m.msg_type === 'system';

        // System
        if (isSystem) {
            let cls = '';
            if (m.msg_type === 'accept') cls = 'accepted';
            if (m.msg_type === 'reject') cls = 'rejected';
            return `<div class="cv2-system-msg ${cls}"><i class="fas fa-info-circle" style="margin-right:4px;"></i> ${esc(m.message)}${m.offer_amount ? '<br><strong>Rs.' + parseInt(m.offer_amount).toLocaleString() + '</strong>' : ''}</div>`;
        }

        // Accept / reject
        if (m.msg_type === 'accept' || m.msg_type === 'reject') {
            const cls = m.msg_type === 'accept' ? 'accepted' : 'rejected';
            const icon = m.msg_type === 'accept' ? 'check-circle' : 'times-circle';
            return `<div class="cv2-system-msg ${cls}"><i class="fas fa-${icon}"></i> ${esc(m.message)}${m.offer_amount ? ' <strong>Rs.' + parseInt(m.offer_amount).toLocaleString() + '</strong>' : ''}</div>`;
        }

        const senderInitial = esc((m.sender_name || 'U').charAt(0).toUpperCase());
        const avatarHtml = m.sender_avatar
            ? `<img src="${esc(m.sender_avatar)}" alt="">`
            : senderInitial;
        const senderUid = m.sender_uid || m.sender_id;

        // Read receipts (only for self messages)
        const readTicks = isSelf ? `<span class="cv2-read-ticks ${parseInt(m.is_read)?'read':''}">✓✓</span>` : '';

        // Image
        if (m.msg_type === 'image') {
            return `<div class="cv2-msg-row ${isSelf?'self':'other'}">
                ${!isSelf ? `<div class="cv2-msg-avatar" onclick="window.location='${profileUrl(senderUid)}'">${avatarHtml}</div>` : ''}
                <div>
                    <div class="cv2-img-bubble" onclick="openLightbox('${esc(m.image_path)}')">
                        <img src="${esc(m.image_path)}" alt="Image" loading="lazy">
                        <div class="img-overlay"><i class="fas fa-search-plus"></i></div>
                    </div>
                    <div class="cv2-msg-meta" style="${isSelf?'justify-content:flex-end':''}">
                        <span class="cv2-msg-time">${fmtTime(m.created_at)}</span>
                        ${readTicks}
                    </div>
                </div>
            </div>`;
        }

        // Payment proof
        if (m.msg_type === 'payment_proof') {
            return `<div class="cv2-msg-row ${isSelf?'self':'other'}">
                ${!isSelf ? `<div class="cv2-msg-avatar" onclick="window.location='${profileUrl(senderUid)}'">${avatarHtml}</div>` : ''}
                <div>
                    <div class="cv2-payment-bubble" onclick="openLightbox('${esc(m.image_path)}')">
                        <div class="payment-label"><i class="fas fa-receipt"></i> Payment Proof</div>
                        <img src="${esc(m.image_path)}" alt="Payment" loading="lazy">
                    </div>
                    <div class="cv2-msg-meta" style="${isSelf?'justify-content:flex-end':''}">
                        <span class="cv2-msg-time">${fmtTime(m.created_at)}</span>
                        ${readTicks}
                    </div>
                </div>
            </div>`;
        }

        // Offer / Counter
        if (m.msg_type === 'offer' || m.msg_type === 'counter') {
            const labelText = m.msg_type === 'offer' ? 'Price Offer' : 'Counter Offer';
            const labelIcon = m.msg_type === 'offer' ? 'tag' : 'exchange-alt';
            const labelCls  = 'type-' + m.msg_type;

            let actionsHtml = '';
            if (!isSelf && convMeta && convMeta.status === 'active') {
                actionsHtml = `<div class="cv2-offer-actions">
                    <button class="oa-accept" onclick="respondOffer('accept')"><i class="fas fa-check"></i> Accept</button>
                    <button class="oa-counter" onclick="openOfferModal()"><i class="fas fa-exchange-alt"></i> Counter</button>
                    <button class="oa-reject" onclick="respondOffer('reject')"><i class="fas fa-times"></i> Reject</button>
                </div>`;
            }

            return `<div class="cv2-msg-row ${isSelf?'self':'other'}">
                ${!isSelf ? `<div class="cv2-msg-avatar" onclick="window.location='${profileUrl(senderUid)}'">${avatarHtml}</div>` : ''}
                <div>
                    <div class="cv2-offer-card">
                        <div class="cv2-offer-label ${labelCls}"><i class="fas fa-${labelIcon}"></i> ${labelText}</div>
                        <div class="cv2-offer-amount">Rs.${parseInt(m.offer_amount).toLocaleString()}</div>
                        ${m.message ? `<div class="cv2-offer-note">${esc(m.message)}</div>` : ''}
                        ${actionsHtml}
                    </div>
                    <div class="cv2-msg-meta" style="${isSelf?'justify-content:flex-end':''}">
                        <span class="cv2-msg-time">${fmtTime(m.created_at)}</span>
                        ${readTicks}
                    </div>
                </div>
            </div>`;
        }

        // Text (default)
        return `<div class="cv2-msg-row ${isSelf?'self':'other'}">
            ${!isSelf ? `<div class="cv2-msg-avatar" onclick="window.location='${profileUrl(senderUid)}'">${avatarHtml}</div>` : ''}
            <div>
                <div class="cv2-bubble">${esc(m.message)}</div>
                <div class="cv2-msg-meta" style="${isSelf?'justify-content:flex-end':''}">
                    <span class="cv2-msg-time">${fmtTime(m.created_at)}</span>
                    ${readTicks}
                </div>
            </div>
        </div>`;
    }

    /* ═══════ CHAT INPUT TOGGLE ═══════ */
    window.toggleChatInput = function() {
        const bar = document.getElementById('cv2InputBar');
        const input = document.getElementById('cv2MsgInput');
        if (!bar || !input) return;

        const isExpanded = bar.classList.contains('expanded');
        if (isExpanded) {
            bar.classList.remove('expanded');
            input.blur();
        } else {
            bar.classList.add('expanded');
            input.focus();
        }
    };

    window.collapseChatInput = function() {
        // Small delay to allow click events on send button to fire first
        setTimeout(() => {
            const bar = document.getElementById('cv2InputBar');
            const input = document.getElementById('cv2MsgInput');
            if (!bar || !input) return;
            // Only collapse if the input is empty
            if (!input.value.trim()) {
                bar.classList.remove('expanded');
            }
        }, 200);
    };

    /* ═══════ OPTIMISTIC UI HELPER ═══════ */
    function appendOptimisticMsg(html) {
        const msgsDiv = document.getElementById('cv2Messages');
        if (!msgsDiv) return;
        msgsDiv.insertAdjacentHTML('beforeend', html);
        msgsDiv.scrollTop = msgsDiv.scrollHeight;
    }

    function nowTime() {
        const d = new Date();
        return d.getHours().toString().padStart(2,'0') + ':' + d.getMinutes().toString().padStart(2,'0');
    }

    /* ═══════ SEND TEXT ═══════ */
    window.sendTextMsg = function() {
        const input = document.getElementById('cv2MsgInput');
        if (!input) return;

        // Check if image is staged
        const preview = document.getElementById('uploadPreview');
        if (preview && preview.classList.contains('show')) {
            uploadAndSendImage();
            return;
        }

        const text = input.value.trim();
        if (!text) return;

        // Optimistic UI: show message instantly
        const optimisticHtml = `<div class="cv2-msg-row self" data-optimistic="1">
            <div>
                <div class="cv2-bubble">${esc(text)}</div>
                <div class="cv2-msg-meta" style="justify-content:flex-end">
                    <span class="cv2-msg-time">${nowTime()}</span>
                    <span class="cv2-read-ticks" style="opacity:0.4;">✓</span>
                </div>
            </div>
        </div>`;
        appendOptimisticMsg(optimisticHtml);

        // Clear input immediately
        input.value = '';
        const bar = document.getElementById('cv2InputBar');
        if (bar) bar.classList.remove('expanded');

        const btn = document.getElementById('cv2SendBtn');

        const fd = new FormData();
        fd.append('action', 'send');
        fd.append('conversation_id', activeConvId);
        fd.append('message', text);
        fd.append('msg_type', 'text');

        fetch('api/chat_v2.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    fetchMessages(false);
                    loadConversations();
                } else {
                    alert(data.error || 'Failed to send');
                }
            })
            .catch(() => {
                alert('Network error');
            });
    };

    /* ═══════ IMAGE UPLOAD ═══════ */
    let stagedFile = null;

    window.handleImageSelect = function(input) {
        const file = input.files[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) { alert('Image must be under 5MB'); input.value = ''; return; }
        stagedFile = file;

        const preview = document.getElementById('uploadPreview');
        const previewImg = document.getElementById('uploadPreviewImg');
        const previewName = document.getElementById('uploadPreviewName');

        const reader = new FileReader();
        reader.onload = e => { previewImg.src = e.target.result; };
        reader.readAsDataURL(file);
        previewName.textContent = file.name;
        preview.classList.add('show');
    };

    window.cancelUpload = function() {
        stagedFile = null;
        document.getElementById('uploadPreview').classList.remove('show');
        document.getElementById('imgFileInput').value = '';
    };

    function uploadAndSendImage() {
        if (!stagedFile) return;

        // Optimistic UI: show image preview instantly
        const previewImg = document.getElementById('uploadPreviewImg');
        const previewSrc = previewImg ? previewImg.src : '';
        const optimisticHtml = `<div class="cv2-msg-row self" data-optimistic="1">
            <div>
                <div class="cv2-img-bubble" style="opacity:0.7;">
                    <img src="${esc(previewSrc)}" alt="Sending..." loading="lazy">
                    <div class="img-overlay" style="display:flex;"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
                <div class="cv2-msg-meta" style="justify-content:flex-end">
                    <span class="cv2-msg-time">${nowTime()}</span>
                    <span class="cv2-read-ticks" style="opacity:0.4;">✓</span>
                </div>
            </div>
        </div>`;
        appendOptimisticMsg(optimisticHtml);

        const btn = document.getElementById('cv2SendBtn');
        if (btn) btn.disabled = true;

        const fd = new FormData();
        fd.append('conversation_id', activeConvId);
        fd.append('image', stagedFile);
        fd.append('upload_type', 'image');

        cancelUpload();

        fetch('api/chat_upload.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (btn) btn.disabled = false;
                if (data.success) {
                    fetchMessages(false);
                    loadConversations();
                } else {
                    alert(data.error || 'Upload failed');
                }
            })
            .catch(() => {
                if (btn) btn.disabled = false;
                alert('Upload failed. Network error.');
            });
    }

    /* ═══════ OFFER ═══════ */
    window.openOfferModal = function() {
        document.getElementById('offerModal').classList.add('show');
        document.getElementById('offerAmountInput').value = '';
        document.getElementById('offerNoteInput').value = '';
        setTimeout(() => document.getElementById('offerAmountInput').focus(), 200);
    };

    window.closeOfferModal = function() {
        document.getElementById('offerModal').classList.remove('show');
    };

    window.submitOffer = function() {
        const amount = parseFloat(document.getElementById('offerAmountInput').value);
        const note = document.getElementById('offerNoteInput').value.trim();
        if (!amount || amount <= 0) { alert('Enter a valid amount'); return; }

        // Optimistic UI: show offer card instantly
        const optimisticHtml = `<div class="cv2-msg-row self" data-optimistic="1">
            <div>
                <div class="cv2-offer-card" style="opacity:0.8;">
                    <div class="cv2-offer-label type-offer"><i class="fas fa-tag"></i> Price Offer</div>
                    <div class="cv2-offer-amount">Rs.${parseInt(amount).toLocaleString()}</div>
                    ${note ? `<div class="cv2-offer-note">${esc(note)}</div>` : ''}
                </div>
                <div class="cv2-msg-meta" style="justify-content:flex-end">
                    <span class="cv2-msg-time">${nowTime()}</span>
                    <span class="cv2-read-ticks" style="opacity:0.4;">✓</span>
                </div>
            </div>
        </div>`;
        appendOptimisticMsg(optimisticHtml);
        closeOfferModal();

        const fd = new FormData();
        fd.append('action', 'send');
        fd.append('conversation_id', activeConvId);
        fd.append('message', note);
        fd.append('msg_type', 'offer');
        fd.append('offer_amount', amount);

        fetch('api/chat_v2.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    fetchMessages(false);
                    loadConversations();
                } else {
                    alert(data.error || 'Failed to send offer');
                }
            })
            .catch(() => alert('Network error'));
    };

    /* ═══════ RESPOND TO OFFER ═══════ */
    window.respondOffer = function(response) {
        const msg = response === 'accept' ? 'Accept this offer?' : 'Reject this offer?';
        if (!confirm(msg)) return;

        const fd = new FormData();
        fd.append('action', 'respond');
        fd.append('conversation_id', activeConvId);
        fd.append('response', response);

        fetch('api/chat_v2.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    selectConv(activeConvId);
                    loadConversations();
                } else {
                    alert(data.error || 'Failed');
                }
            })
            .catch(() => alert('Network error'));
    };

    /* ═══════ REPORT ═══════ */
    window.openReportModal = function() {
        document.getElementById('reportModal').classList.add('show');
        document.getElementById('reportReasonInput').value = '';
    };

    window.closeReportModal = function() {
        document.getElementById('reportModal').classList.remove('show');
    };

    window.submitReport = function() {
        const reason = document.getElementById('reportReasonInput').value.trim();
        if (!reason) { alert('Please provide a reason'); return; }

        const fd = new FormData();
        fd.append('action', 'report');
        fd.append('conversation_id', activeConvId);
        fd.append('reason', reason);

        fetch('api/chat_v2.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                closeReportModal();
                alert(data.success ? '✅ Report submitted. Admin will review.' : (data.error || 'Failed'));
            })
            .catch(() => alert('Network error'));
    };

    /* ═══════ PRESENCE UPDATE ═══════ */
    function updatePresence(p) {
        const el = document.getElementById('cv2Presence');
        const txt = document.getElementById('cv2PresenceText');
        if (!el || !txt) return;
        if (p.online) {
            el.className = 'cv2-header-presence online';
            txt.textContent = 'Online';
        } else {
            el.className = 'cv2-header-presence offline';
            txt.textContent = lastSeenText(p.last_seen);
        }
    }

    function updateStatusBar() {
        const existing = document.getElementById('cv2StatusBar');
        if (existing || !convMeta) return;
        if (convMeta.status === 'active' || convMeta.type === 'direct') return;

        const sMap = {
            'accepted': { cls: 'accepted', icon: 'check-circle', text: 'Offer accepted!' },
            'rejected': { cls: 'rejected', icon: 'times-circle', text: 'Offer was declined' },
            'expired':  { cls: 'expired',  icon: 'hourglass-end', text: 'Negotiation expired' },
        };
        const s = sMap[convMeta.status];
        if (!s) return;
        let checkoutLink = '';
        if (convMeta.status === 'accepted' && parseInt(convMeta.buyer_id) === CURRENT_USER) {
            checkoutLink = `<a href="checkout.php?nego_id=${convMeta.id}"><i class="fas fa-shopping-cart"></i> Checkout</a>`;
        }
        const inputBar = document.querySelector('.cv2-input-bar');
        if (inputBar) {
            inputBar.insertAdjacentHTML('beforebegin', `<div class="cv2-status-bar ${s.cls}" id="cv2StatusBar"><i class="fas fa-${s.icon}"></i> ${s.text} ${checkoutLink}</div>`);
        }
    }

    /* ═══════ LIGHTBOX ═══════ */
    window.openLightbox = function(src) {
        document.getElementById('lightboxImg').src = src;
        document.getElementById('imageLightbox').classList.add('show');
    };

    window.closeLightbox = function() {
        document.getElementById('imageLightbox').classList.remove('show');
    };

    /* ═══════ POLLING ═══════ */
    pollInterval = setInterval(() => {
        if (activeConvId) fetchMessages(false);
        loadConversations();
    }, 2000);

    // Pause when tab hidden
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearInterval(pollInterval);
        } else {
            sendHeartbeat();
            if (activeConvId) fetchMessages(false);
            loadConversations();
            pollInterval = setInterval(() => {
                if (activeConvId) fetchMessages(false);
                loadConversations();
            }, 2000);
        }
    });

    /* ═══════ NAV BADGE UPDATER ═══════ */
    function updateNavBadge() {
        fetch('api/chat_v2.php?action=unread_count')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const badge = document.querySelector('.chat-badge-count, .chat-nav-badge');
                    if (badge) {
                        if (data.count > 0) {
                            badge.textContent = data.count;
                            badge.style.display = 'flex';
                        } else {
                            badge.style.display = 'none';
                        }
                    }
                }
            }).catch(()=>{});
    }
    updateNavBadge();
    setInterval(updateNavBadge, 15000);

    /* ═══════ AUTO-START ═══════ */
    loadConversations();

    // If we came from a listing or seller profile, auto-start the conversation
    const autoListingId = <?php echo $listingId; ?>;
    const autoDirectSeller = <?php echo $directSellerId; ?>;
    const autoConvId = <?php echo $openConvId; ?>;

    if (autoConvId > 0) {
        setTimeout(() => selectConv(autoConvId), 500);
    } else if (autoListingId > 0) {
        const fd = new FormData();
        fd.append('action', 'start');
        fd.append('listing_id', autoListingId);
        fetch('api/chat_v2.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    loadConversations();
                    setTimeout(() => selectConv(data.conversation_id), 600);
                }
            });
    } else if (autoDirectSeller > 0) {
        const fd = new FormData();
        fd.append('action', 'start');
        fd.append('direct_seller_id', autoDirectSeller);
        fetch('api/chat_v2.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    switchTab('direct');
                    setTimeout(() => selectConv(data.conversation_id), 600);
                }
            });
    }

    /* ═══════ MOBILE BACK ═══════ */
    window.closeMobileChat = function() {
        document.getElementById('chatShell')?.classList.remove('chat-open');
    };

    /* ═══════ SEARCH FILTER ═══════ */
    window.filterConversations = function(query) {
        const q = query.toLowerCase().trim();
        document.querySelectorAll('.cv2-item').forEach(el => {
            const name = el.querySelector('.cv2-name')?.textContent?.toLowerCase() || '';
            const listing = el.querySelector('.cv2-listing')?.textContent?.toLowerCase() || '';
            const preview = el.querySelector('.cv2-preview')?.textContent?.toLowerCase() || '';
            const match = !q || name.includes(q) || listing.includes(q) || preview.includes(q);
            el.style.display = match ? '' : 'none';
        });
    };

    /* ═══════ CLEANUP ═══════ */
    window.addEventListener('beforeunload', () => {
        clearInterval(pollInterval);
        clearInterval(heartbeatInt);
        if (timerInterval) clearInterval(timerInterval);
    });

})();
</script>

<?php include 'includes/footer.php'; ?>
