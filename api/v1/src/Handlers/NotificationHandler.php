<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Pusher\Pusher;
use App\Services\MonologHandler;
use App\Services\NotificationAggregatorService;
use App\Utils\PaginationHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Creates and serves tenant notifications, user preferences, and delivery
 * statistics.
 *
 * Persistent notification delivery is coordinated with
 * NotificationAggregatorService; configured Pusher credentials enable realtime
 * events for notifications created directly by this handler.
 * app/routes/notifications.php exposes the user-facing listing, read, preference,
 * and statistics endpoints.
 */
class NotificationHandler extends BaseHandler
{
    private ?Pusher $pusher = null;
    private NotificationAggregatorService $aggregator;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('notification');
        $this->aggregator = new NotificationAggregatorService($db, $this->logger);
        $this->initializePusher();
    }

    /**
     * Initialize Pusher configuration for real-time notification delivery.
     *
     * Reads Pusher credentials from environment variables and establishes
     * connection for real-time push notifications to connected clients.
     * If credentials are missing or connection fails, notifications are queued
     * for batch delivery only.
     *
     * Called from:
     * - NotificationHandler::__construct() [initialization]
     *
     * @internal Initialization helper - called once on construct
     *
     * @return void
     *
     * @see createNotification() Uses Pusher for real-time delivery
     */
    private function initializePusher(): void
    {
        $key    = getenv('PUSHER_APP_KEY');
        $secret = getenv('PUSHER_APP_SECRET');
        $appId  = getenv('PUSHER_APP_ID');

        if (!$key || !$secret || !$appId) {
            $this->pusher = null;
            return;
        }

        try {
            $this->pusher = new Pusher(
                $key,
                $secret,
                $appId,
                [
                    'cluster' => getenv('PUSHER_APP_CLUSTER') ?: 'mt1',
                    'useTLS'  => true
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pusher init failed', [
                'message' => $e->getMessage()
            ]);
            $this->pusher = null;
        }
    }

    /**
     * Create and persist a notification, then broadcast via Pusher if configured.
     *
     * This internal helper:
     * 1. Saves notification to database (persistent storage)
     * 2. Broadcasts via Pusher channel for real-time delivery
     * 3. Handles failures gracefully (logs warnings, doesn't block)
     *
     * Called from:
     * - sendLowStockAlert() [Line 132]
     * - sendExpiryAlert() [Line 184]
     * - sendNewOrderNotification() [Line 234]
     * - sendOrderStatusNotification() [Line 281]
     *
     * @internal Used internally for notification creation and delivery
     *
     * @param int $userId User who will receive the notification
     * @param int $tenantId Tenant owner of the notification
     * @param string $type Notification type (low_stock_alert, product_expiry, etc)
     * @param string $title Display title shown to user
     * @param array $data Additional context data (product name, amounts, etc)
     *
     * @return int|null The created notification ID, or null if creation failed
     *
     * @see sendLowStockAlert() Creates low stock notifications
     * @see sendExpiryAlert() Creates expiry warnings
     * @see sendNewOrderNotification() Creates new order alerts
     */
    private function createNotification(
        int $userId,
        int $tenantId,
        string $type,
        string $title,
        array $data = []
    ): ?int {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO notifications (
                    tenant_id,
                    user_id,
                    type,
                    title,
                    message,
                    data,
                    is_read,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
            ");

            $stmt->execute([
                $tenantId,
                $userId,
                $type,
                $title,
                $data['message'] ?? '',
                json_encode($data, JSON_UNESCAPED_UNICODE)
            ]);

            $notificationId = (int) $this->db->lastInsertId();

            if ($this->pusher) {
                try {
                    $this->pusher->trigger('user-' . $userId, 'new-notification', [
                        'id' => $notificationId,
                        'type' => $type,
                        'title' => $title,
                        'data' => $data
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->warning('Pusher notification failed', [
                        'user_id' => $userId,
                        'notification_id' => $notificationId,
                        'message' => $e->getMessage()
                    ]);
                }
            }

            return $notificationId;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create notification', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'type' => $type,
                'message' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Send low-stock alert to inventory managers and admins.
     *
     * Triggered automatically when product stock falls below minimum threshold.
     * This is an event-driven method called from inventory adjustment listeners.
     *
     * Event Trigger:
     * - Dispatched from: InventoryService::adjustStock()
     * - Event name: inventory.stock_low
     * - Trigger point: Stock < minimum_stock
     *
     * Behavior:
     * 1. Query product and current stock level
     * 2. Find all admins and inventory managers in tenant
     * 3. Queue high-priority notification for each
     * 4. Notifications sent via NotificationAggregatorService
     *
     * @internal Event-driven - called automatically from inventory changes, not directly
     *
     * @param int $productId Product ID that has low stock
     *
     * @return void
     *
     * @fired-by InventoryAdjusted event (inventory.stock_low)
     * @called-from InventoryService::adjustStock()
     *
     * @see createNotification() Lower-level notification creation
     * @see sendExpiryAlert() Related product alert
     * @see sendNewOrderNotification() Other notification types
     */
    public function sendLowStockAlert(int $productId): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    p.tenant_id,
                    p.name,
                    p.product_code AS sku,
                    (SELECT COALESCE(SUM(bp.quantity), 0)
                     FROM branch_products bp
                     WHERE bp.product_id = p.id
                       AND bp.tenant_id  = p.tenant_id) AS current_stock,
                    p.min_quantity AS minimum_stock,
                    u.id AS user_id
                FROM products p
                JOIN users u ON u.tenant_id = p.tenant_id
                JOIN roles r ON r.id = u.role_id
                WHERE p.id = ?
                  AND (r.name = 'admin' OR r.name = 'inventory_manager')
            ");

            $stmt->execute([$productId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (!empty($results)) {
                $product = $results[0];

                foreach ($results as $row) {
                    $this->aggregator->queueNotification(
                        (int) $row['user_id'],
                        (int) $product['tenant_id'],
                        'low_stock_alert',
                        [
                            'product_id' => $productId,
                            'product_name' => $product['name'],
                            'sku' => $product['sku'],
                            'current_stock' => $product['current_stock'],
                            'minimum_stock' => $product['minimum_stock'],
                        ],
                        NotificationAggregatorService::PRIORITY_HIGH
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('sendLowStockAlert error', [
                'product_id' => $productId,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send product expiry alert to inventory managers and admins.
     *
     * Triggered when products approach or reach expiration date.
     * This is a scheduled event-driven method for time-sensitive inventory management.
     *
     * Event Trigger:
     * - Dispatched from: InventoryService::checkExpiringBatches()
     * - Event name: inventory.expiry_alert
     * - Trigger: Daily cron check or manual inventory audit
     *
     * Alert Criteria:
     * - Product batch has expiry_date <= today + threshold days
     * - Threshold is configurable per tenant via settings (default: 30 days)
     * - Targets admins and inventory managers only
     * - Sent as direct notifications (not queued)
     *
     * @internal Event-driven - called from scheduled tasks, not from direct API
     *
     * @param int $productId Product ID with expiring stock
     * @param int|null $thresholdDays Days until expiry to trigger alert (default: from settings, fallback: 30)
     *
     * @return void
     *
     * @fired-by InventoryService::checkExpiringBatches()
     * @called-from Cron job or manual batch check
     *
     * @see sendLowStockAlert() Related stock alert
     * @see createNotification() Lower-level notification creation
     */
    public function sendExpiryAlert(int $productId, ?int $thresholdDays = null): void
    {
        try {
            // Determine threshold from settings or use default
            if ($thresholdDays === null) {
                $thresholdDays = $this->getExpiryAlertThreshold($productId);
            }

            $thresholdDays = max(1, min(365, $thresholdDays)); // Clamp between 1 and 365 days

            $stmt = $this->db->prepare("
                SELECT
                    p.tenant_id,
                    p.name,
                    p.product_code AS sku,
                    MIN(b.expiry_date) AS expiry_date,
                    u.id AS user_id
                FROM products p
                JOIN product_expiry b
                  ON b.product_id = p.id AND b.tenant_id = p.tenant_id
                JOIN users u ON u.tenant_id = p.tenant_id
                JOIN roles r ON r.id = u.role_id
                WHERE p.id = ?
                  AND (r.name = 'admin' OR r.name = 'inventory_manager')
                  AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                GROUP BY p.tenant_id, p.name, p.product_code, u.id
            ");

            $stmt->execute([$productId, $thresholdDays]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (!empty($results)) {
                $product = $results[0];

                foreach ($results as $row) {
                    $this->createNotification(
                        (int) $row['user_id'],
                        (int) $product['tenant_id'],
                        'product_expiry',
                        'تنبيه انتهاء الصلاحية',
                        [
                            'message' => "المنتج {$product['name']} ينتهي في {$product['expiry_date']}",
                            'product_id' => $productId,
                            'product_name' => $product['name'],
                            'sku' => $product['sku'],
                            'expiry_date' => $product['expiry_date']
                        ]
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('sendExpiryAlert error', [
                'product_id' => $productId,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get configured expiry alert threshold from tenant settings or use default.
     *
     * @internal Helper for sendExpiryAlert() to fetch configurable threshold
     *
     * @param int $productId Product ID to determine tenant
     *
     * @return int Number of days until expiry to trigger alert (1-365)
     */
    private function getExpiryAlertThreshold(int $productId): int
    {
        try {
            // Get tenant from product
            $stmt = $this->db->prepare("
                SELECT tenant_id FROM products WHERE id = ? LIMIT 1
            ");
            $stmt->execute([$productId]);
            $tenantId = (int) ($stmt->fetchColumn() ?? 0);

            if (!$tenantId) {
                return 30; // Default fallback
            }

            // Try to get from settings
            $stmt = $this->db->prepare("
                SELECT value FROM settings
                WHERE tenant_id = ?
                  AND key_name = 'inventory.expiry_alert_threshold_days'
                LIMIT 1
            ");
            $stmt->execute([$tenantId]);
            $setting = (int) ($stmt->fetchColumn() ?? 0);

            return $setting > 0 ? $setting : 30; // Default to 30 if not set or invalid
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get expiry alert threshold, using default', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return 30;
        }
    }

    /**
     * Send new order confirmation notification to sales managers and admins.
     *
     * Triggered when a new sales order is created in the system.
     * Notifies management-level users about incoming orders for oversight.
     *
     * Event Trigger:
     * - Dispatched from: SalesHandler::create()
     * - Event name: order.created
     * - Trigger point: After successful order insertion
     *
     * Notification Details:
     * - Priority: NORMAL (not urgent)
     * - Recipients: admins and sales managers
     * - Queued: Sent via NotificationAggregatorService for batch processing
     * - Contains: Order ID, customer name, total amount
     *
     * @internal Event-driven - called from order creation event
     *
     * @param int $orderId Sales order ID
     *
     * @return void
     *
     * @fired-by SalesHandler::create() after order insertion
     * @called-from OrderCreatedListener
     *
     * @see sendOrderStatusNotification() Related status change notification
     * @see sendLowStockAlert() Other event notifications
     */
    public function sendNewOrderNotification(int $orderId): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    s.id,
                    s.tenant_id,
                    s.total_amount,
                    c.name AS customer_name,
                    u.id AS user_id
                FROM sales s
                JOIN customers c ON c.id = s.customer_id
                JOIN users u ON u.tenant_id = s.tenant_id
                JOIN roles r ON r.id = u.role_id
                WHERE s.id = ?
                  AND (r.name = 'admin' OR r.name = 'sales_manager')
            ");

            $stmt->execute([$orderId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (!empty($results)) {
                $order = $results[0];

                foreach ($results as $row) {
                    $this->aggregator->queueNotification(
                        (int) $row['user_id'],
                        (int) $order['tenant_id'],
                        'new_order',
                        [
                            'order_id' => $orderId,
                            'customer_name' => $order['customer_name'],
                            'amount' => (float) $order['total_amount']
                        ],
                        NotificationAggregatorService::PRIORITY_NORMAL
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('sendNewOrderNotification error', [
                'order_id' => $orderId,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send order status change notification to sales managers and admins.
     *
     * Triggered when an order transitions between statuses (processing → shipped → delivered, etc).
     * Keeps management informed of order progress for customer service coordination.
     *
     * Event Trigger:
     * - Dispatched from: SalesHandler::updateStatus()
     * - Event name: order.status_changed
     * - Trigger point: After status update in database
     *
     * Supported Status Transitions:
     * - processing → جاري المعالجة
     * - shipped → تم الشحن
     * - delivered → تم التسليم
     * - cancelled → تم الإلغاء
     * - paid → تم الدفع
     *
     * @internal Event-driven - called from status change events
     *
     * @param int $orderId Sales order ID being updated
     * @param string $status New order status (processing, shipped, delivered, etc)
     *
     * @return void
     *
     * @fired-by SalesHandler::updateStatus() after status update
     * @called-from OrderStatusListener
     *
     * @see sendNewOrderNotification() Related order notifications
     */
    public function sendOrderStatusNotification(int $orderId, string $status): void
    {
        try {
            $statusMessages = [
                'processing' => 'جاري المعالجة',
                'shipped' => 'تم الشحن',
                'delivered' => 'تم التسليم',
                'cancelled' => 'تم الإلغاء',
                'paid' => 'تم الدفع'
            ];

            $statusLabel = $statusMessages[$status] ?? $status;

            $stmt = $this->db->prepare("
                SELECT
                    s.id,
                    s.tenant_id,
                    s.customer_id,
                    c.name AS customer_name,
                    u.id AS user_id
                FROM sales s
                JOIN customers c ON c.id = s.customer_id
                JOIN users u ON u.tenant_id = s.tenant_id
                JOIN roles r ON r.id = u.role_id
                WHERE s.id = ?
                  AND (r.name = 'admin' OR r.name = 'sales_manager')
            ");

            $stmt->execute([$orderId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (!empty($results)) {
                $order = $results[0];

                foreach ($results as $row) {
                    $this->createNotification(
                        (int) $row['user_id'],
                        (int) $order['tenant_id'],
                        'order_status_change',
                        'تحديث حالة الطلب',
                        [
                            'message' => "الطلب #{$order['id']}: {$statusLabel}",
                            'order_id' => $orderId,
                            'customer_name' => $order['customer_name'],
                            'status' => $status
                        ]
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('sendOrderStatusNotification error', [
                'order_id' => $orderId,
                'status' => $status,
                'message' => $e->getMessage()
            ]);
        }
    }

    /** Return the authenticated user's paginated notifications, excluding new-order entries. */
    public function getNotifications(Request $request, Response $response, array $args = []): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $userId = $this->extractUserId($request);
            if (!$userId) {
                return $this->errorResponse($response, 'User ID is required', 400);
            }

            $qp = $request->getQueryParams();
            [$page, $perPage, $offset] = PaginationHelper::fromArray($qp, 10, 50);

            $countStmt = $this->db->prepare("
                SELECT COUNT(*) AS total
                FROM notifications
                WHERE user_id = ? AND tenant_id = ? AND type <> 'new_order'
            ");
            $countStmt->execute([(int) $userId, (int) $tenantId]);
            $total = (int) (($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0));

            $stmt = $this->db->prepare("
                SELECT id, type, title, message, data, is_read, created_at, read_at,
                       aggregated_count, priority
                FROM notifications
                WHERE user_id = ? AND tenant_id = ? AND type <> 'new_order'
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, (int) $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, (int) $tenantId, PDO::PARAM_INT);
            $stmt->bindValue(3, (int) $perPage, PDO::PARAM_INT);
            $stmt->bindValue(4, (int) $offset, PDO::PARAM_INT);
            $stmt->execute();

            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, [
                'notifications' => $notifications,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => (int) ceil($total / $perPage)
                ]
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('getNotifications error', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to retrieve notifications', 500);
        }
    }

    /** Mark one notification read when it belongs to the authenticated user and tenant. */
    public function markAsRead(Request $request, Response $response, array $args = []): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $userId = $this->extractUserId($request);
            if (!$userId) {
                return $this->errorResponse($response, 'User ID is required', 400);
            }

            $notificationId = isset($args['id']) ? (int) $args['id'] : 0;
            if ($notificationId <= 0) {
                return $this->errorResponse($response, 'Notification ID is required', 400);
            }

            $stmt = $this->db->prepare("
                UPDATE notifications
                SET is_read = 1, read_at = NOW()
                WHERE id = ? AND user_id = ? AND tenant_id = ?
            ");
            $stmt->execute([$notificationId, (int) $userId, (int) $tenantId]);

            return $this->successResponse($response, [
                'notification' => [
                    'id' => $notificationId,
                    'is_read' => 1,
                    'read_at' => date('c'),
                ],
                'message' => 'Notification marked as read'
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('markAsRead error', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to mark notification as read', 500);
        }
    }

    /** Mark every unread notification for a user and tenant as read. */
    public function markAllAsReadInternal(int $userId, int $tenantId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE notifications
                SET is_read = 1, read_at = NOW()
                WHERE user_id = ? AND tenant_id = ? AND is_read = 0
            ");

            return $stmt->execute([$userId, $tenantId]);
        } catch (\Throwable $e) {
            $this->logger->error('markAllAsReadInternal error', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'message' => $e->getMessage()
            ]);

            return false;
        }
    }

    /** Count unread notifications for a user, excluding new-order entries. */
    public function getUnreadCount(int $userId, int $tenantId): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) AS unread_count
                FROM notifications
                WHERE user_id = ? AND tenant_id = ? AND is_read = 0 AND type <> 'new_order'
            ");
            $stmt->execute([$userId, $tenantId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return (int) ($result['unread_count'] ?? 0);
        } catch (\Throwable $e) {
            $this->logger->error('getUnreadCount error', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'message' => $e->getMessage()
            ]);

            return 0;
        }
    }

    /** Delete read tenant notifications older than the retention interval. */
    public function deleteOldNotifications(int $tenantId, int $daysToKeep = 30): bool
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM notifications
                WHERE tenant_id = ?
                  AND is_read = 1
                  AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");

            return $stmt->execute([$tenantId, $daysToKeep]);
        } catch (\Throwable $e) {
            $this->logger->error('deleteOldNotifications error', [
                'tenant_id' => $tenantId,
                'days_to_keep' => $daysToKeep,
                'message' => $e->getMessage()
            ]);

            return false;
        }
    }

    /** Return notification delivery preferences for the authenticated user. */
    public function getPreferences(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $userId = $this->extractUserId($request);
            if (!$userId) {
                return $this->errorResponse($response, 'User ID is required', 400);
            }

            $stmt = $this->db->prepare("
                SELECT 
                    id,
                    notification_type,
                    delivery_mode,
                    batch_interval_minutes,
                    threshold_enabled,
                    threshold_value,
                    enable_in_app,
                    enable_email,
                    enable_push
                FROM notification_preferences
                WHERE user_id = ? AND tenant_id = ?
                ORDER BY notification_type
            ");

            $stmt->execute([$userId, $tenantId]);
            $preferences = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, [
                'preferences' => $preferences
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('getPreferences error', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to retrieve preferences', 500);
        }
    }

    /** Insert or update the authenticated user's preference for one notification type. */
    public function updatePreferences(Request $request, Response $response, array $args = []): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $userId = $this->extractUserId($request);
            if (!$userId) {
                return $this->errorResponse($response, 'User ID is required', 400);
            }

            $data = $this->extractAndValidateRequestData($request, [
                'notification_type',
                'delivery_mode'
            ]);

            $validModes = ['instant', 'batched', 'digest', 'disabled'];
            if (!in_array($data['delivery_mode'], $validModes)) {
                return $this->errorResponse($response, 'Invalid delivery mode', 400);
            }

            $checkStmt = $this->db->prepare("
                SELECT id FROM notification_preferences
                WHERE user_id = ? AND tenant_id = ? AND notification_type = ?
            ");
            $checkStmt->execute([$userId, $tenantId, $data['notification_type']]);
            $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                $stmt = $this->db->prepare("
                    UPDATE notification_preferences
                    SET delivery_mode = ?,
                        batch_interval_minutes = ?,
                        threshold_enabled = ?,
                        threshold_value = ?,
                        enable_in_app = ?,
                        enable_email = ?,
                        enable_push = ?,
                        updated_at = NOW()
                    WHERE user_id = ? AND tenant_id = ? AND notification_type = ?
                ");

                $stmt->execute([
                    $data['delivery_mode'],
                    $data['batch_interval_minutes'] ?? 30,
                    $data['threshold_enabled'] ?? 0,
                    $data['threshold_value'] ?? null,
                    $data['enable_in_app'] ?? 1,
                    $data['enable_email'] ?? 0,
                    $data['enable_push'] ?? 0,
                    $userId,
                    $tenantId,
                    $data['notification_type']
                ]);
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO notification_preferences (
                        tenant_id,
                        user_id,
                        notification_type,
                        delivery_mode,
                        batch_interval_minutes,
                        threshold_enabled,
                        threshold_value,
                        enable_in_app,
                        enable_email,
                        enable_push
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $tenantId,
                    $userId,
                    $data['notification_type'],
                    $data['delivery_mode'],
                    $data['batch_interval_minutes'] ?? 30,
                    $data['threshold_enabled'] ?? 0,
                    $data['threshold_value'] ?? null,
                    $data['enable_in_app'] ?? 1,
                    $data['enable_email'] ?? 0,
                    $data['enable_push'] ?? 0
                ]);
            }

            return $this->successResponse($response, [
                'message' => 'تم تحديث التفضيلات بنجاح',
                'preference' => [
                    'notification_type' => $data['notification_type'],
                    'delivery_mode' => $data['delivery_mode']
                ]
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('updatePreferences error', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to update preferences', 500);
        }
    }

    /** Return per-type counts, unread totals, and aggregation metrics for the user. */
    public function getStatistics(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $userId = $this->extractUserId($request);
            if (!$userId) {
                return $this->errorResponse($response, 'User ID is required', 400);
            }

            $typeStmt = $this->db->prepare("
                SELECT 
                    type,
                    COUNT(*) as count,
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count
                FROM notifications
                WHERE user_id = ? AND tenant_id = ?
                GROUP BY type
            ");
            $typeStmt->execute([$userId, $tenantId]);
            $byType = $typeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $unreadStmt = $this->db->prepare("
                SELECT COUNT(*) as total_unread
                FROM notifications
                WHERE user_id = ? AND tenant_id = ? AND is_read = 0
            ");
            $unreadStmt->execute([$userId, $tenantId]);
            $unread = $unreadStmt->fetch(PDO::FETCH_ASSOC);

            $aggStmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as aggregated_notifications,
                    SUM(aggregated_count) as total_events,
                    AVG(aggregated_count) as avg_events_per_notification
                FROM notifications
                WHERE user_id = ? AND tenant_id = ? AND aggregated_count > 1
            ");
            $aggStmt->execute([$userId, $tenantId]);
            $aggregation = $aggStmt->fetch(PDO::FETCH_ASSOC);

            return $this->successResponse($response, [
                'total_unread' => (int) ($unread['total_unread'] ?? 0),
                'by_type' => $byType,
                'aggregation' => [
                    'aggregated_notifications' => (int) ($aggregation['aggregated_notifications'] ?? 0),
                    'total_events' => (int) ($aggregation['total_events'] ?? 0),
                    'avg_events_per_notification' => round((float) ($aggregation['avg_events_per_notification'] ?? 0), 2),
                    'reduction_percentage' => $this->calculateReductionPercentage(
                        (int) ($aggregation['aggregated_notifications'] ?? 0),
                        (int) ($aggregation['total_events'] ?? 0)
                    )
                ]
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('getStatistics error', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to retrieve statistics', 500);
        }
    }

    /** Calculate the percentage of source events consolidated into fewer notifications. */
    private function calculateReductionPercentage(int $notifications, int $events): float
    {
        if ($events === 0) {
            return 0.0;
        }

        $reduction = (($events - $notifications) / $events) * 100;
        return round($reduction, 2);
    }
}
