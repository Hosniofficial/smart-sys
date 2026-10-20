<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;

/**
 * Provides reusable input validation and recursive string sanitization helpers.
 *
 * Validation rules are supplied by callers. The exists and unique rules query
 * through $GLOBALS['db']; sanitization strips ASCII control characters and
 * HTML-escapes string values.
 */
class ValidationHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('validation');
    }

    /** Validate input against pipe-delimited rules; throw when any rule fails. */
    public static function validate($data, $rules)
    {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $fieldRules = explode('|', $fieldRules);

            foreach ($fieldRules as $rule) {
                if (strpos($rule, ':') !== false) {
                    list($rule, $parameter) = explode(':', $rule);
                } else {
                    $parameter = null;
                }

                $value = $data[$field] ?? null;

                switch ($rule) {
                    case 'required':
                        if (empty($value) && $value !== '0' && $value !== 0) {
                            $errors[$field][] = "الحقل {$field} مطلوب.";
                        }
                        break;

                    case 'email':
                        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field][] = "يجب أن يكون {$field} بريدًا إلكترونيًا صالحًا.";
                        }
                        break;

                    case 'numeric':
                        if (!empty($value) && !is_numeric($value)) {
                            $errors[$field][] = "يجب أن يكون {$field} رقمًا.";
                        }
                        break;

                    case 'min':
                        if (!empty($value)) {
                            if (is_numeric($value) && $value < $parameter) {
                                $errors[$field][] = "يجب ألا يقل {$field} عن {$parameter}.";
                            } elseif (is_string($value) && strlen($value) < $parameter) {
                                $errors[$field][] = "يجب ألا يقل طول {$field} عن {$parameter} حرفًا.";
                            }
                        }
                        break;

                    case 'max':
                        if (!empty($value)) {
                            if (is_numeric($value) && $value > $parameter) {
                                $errors[$field][] = "يجب ألا يزيد {$field} عن {$parameter}.";
                            } elseif (is_string($value) && strlen($value) > $parameter) {
                                $errors[$field][] = "يجب ألا يزيد طول {$field} عن {$parameter} حرفًا.";
                            }
                        }
                        break;

                    case 'date':
                        if (!empty($value)) {
                            $date = date_parse($value);
                            if ($date['error_count'] > 0) {
                                $errors[$field][] = "يجب أن يكون {$field} تاريخًا صالحًا.";
                            }
                        }
                        break;

                    case 'array':
                        if (!empty($value) && !is_array($value)) {
                            $errors[$field][] = "يجب أن يكون {$field} مصفوفة.";
                        }
                        break;

                    case 'exists':
                        if (!empty($value)) {
                            list($table, $column, $tenantField) = array_pad(explode(',', $parameter), 3, null);
                            $query = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
                            $params = [$value];

                            if ($tenantField === 'tenant_id' && !empty($data['tenant_id'])) {
                                $query .= " AND tenant_id = ?";
                                $params[] = $data['tenant_id'];
                            }
                            if (!isset($GLOBALS['db'])) {
                                throw new \Exception('Database connection not available in ValidationHandler::validate()');
                            }
                            $stmt = $GLOBALS['db']->prepare($query);
                            $stmt->execute($params);
                            if ($stmt->fetchColumn() == 0) {
                                $errors[$field][] = "القيمة المختارة لـ {$field} غير صالحة.";
                            }
                        }
                        break;

                    case 'unique':
                        if (!empty($value)) {
                            list($table, $column, $except, $tenantField) = array_pad(explode(',', $parameter), 4, null);
                            $query = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
                            $params = [$value];
                            if ($except) {
                                $query .= " AND id != ?";
                                $params[] = $except;
                            }

                            if ($tenantField === 'tenant_id' && !empty($data['tenant_id'])) {
                                $query .= " AND tenant_id = ?";
                                $params[] = $data['tenant_id'];
                            }
                            if (!isset($GLOBALS['db'])) {
                                throw new \Exception('Database connection not available in ValidationHandler::validate()');
                            }
                            $stmt = $GLOBALS['db']->prepare($query);
                            $stmt->execute($params);
                            if ($stmt->fetchColumn() > 0) {
                                $errors[$field][] = "قيمة {$field} مستخدمة بالفعل.";
                            }
                        }
                        break;
                }
            }
        }

        if (!empty($errors)) {
            throw new \Exception('البيانات المقدمة غير صالحة: ' . implode(', ', $errors));
        }

        return true;
    }

    /** Recursively remove ASCII control characters and HTML-escape strings. */
    public static function sanitize($data)
    {
        if (is_array($data)) {
            return array_map([self::class, 'sanitize'], $data);
        }

        if (is_string($data)) {

            $data = preg_replace('/[\x00-\x1F\x7F]/u', '', $data);

            $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        }

        return $data;
    }
}
