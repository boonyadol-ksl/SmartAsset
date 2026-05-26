<?php
require_once __DIR__ . '/../config.php';
class Database
{
    private static $instance = null;
    private $conn;
    private function __construct()
    {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die(json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    public function prepare($sql)
    {
        return $this->conn->prepare($sql);
    }
    public function getConnection()
    {
        return $this->conn;
    }
    public function query($sql, $params = [])
    {
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    public function execute($sql, $params = [])
    {
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute($params);
    }
    public function fetchAll($sql, $params = [])
    {
        return $this->query($sql, $params)->fetchAll();
    }
    public function fetchOne($sql, $params = [])
    {
        return $this->query($sql, $params)->fetch();
    }
    public function insert($table, $data)
    {
        $columns = implode(',', array_keys($data));
        $placeholders = ':' . implode(',:', array_keys($data));
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, $data);
        return (int) $this->conn->lastInsertId();
    }
    public function update($table, $data, $where, $whereParams = [])
    {
        $set = implode(',', array_map(function ($k) {
            return "{$k}=:{$k}";
        }, array_keys($data)));
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        $stmt = $this->query($sql, array_merge($data, $whereParams));
        return $stmt->rowCount();
    }
    public function lastInsertId()
    {
        return (int) $this->conn->lastInsertId();
    }
    public function beginTransaction()
    {
        return $this->conn->beginTransaction(); // เปลี่ยน $this->conn เป็นชื่อตัวแปรในคลาสคุณ
    }
    public function commit()
    {
        return $this->conn->commit();
    }
    public function rollBack()
    {
        return $this->conn->rollBack();
    }
}
function dd($data=null) {
    // 1. ล้าง Output ทั้งหมดทิ้ง (แก้ปัญหา Headers already sent)
    if (ob_get_length()) ob_end_clean();
    // 2. ปิด Error Reporting เพื่อไม่ให้แสดง Warning ปนออกมา
    error_reporting(0);
    echo '<html><body style="background:#18171B; color:#ccc; font-family:monospace; padding:20px;">';
    echo '<style>
            .toggle { cursor: pointer; color: #76c7c0; }
            .content { margin-left: 20px; display: block; }
            .hidden { display: none; }
          </style>';
    echo '<h2>DEBUG DATA:</h2>';
    // 3. ฟังก์ชัน Recursive สำหรับวาด Tree (ช่วยให้ข้อมูลเยอะไม่ค้าง)
    function renderTree($val) {
        if (is_array($val) || is_object($val)) {
            echo '<span class="toggle" onclick="this.nextElementSibling.classList.toggle(\'hidden\')">▼</span>';
            echo '<div class="content">';
            foreach ($val as $key => $value) {
                echo '<div><strong>' . htmlspecialchars($key) . ':</strong> ';
                renderTree($value);
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<span style="color:#d19a66;">' . htmlspecialchars(var_export($val, true)) . '</span>';
        }
    }
    renderTree($data);
    echo '</body></html>';
    exit;
}
/**
 * แปลง JSON string ให้เป็น Associative Array อย่างปลอดภัย
 *
 * @param string|null $jsonString ข้อความ JSON ที่ต้องการแปลง
 * @return array|null คืนค่าเป็น Array หากสำเร็จ, คืนค่า null หากเกิดข้อผิดพลาดหรือข้อมูลว่างเปล่า
 */
function jsonDecode(?string $jsonString): ?array
{
    // ตรวจสอบข้อมูลก่อนประมวลผล เพื่อลดภาระของระบบ
    if (empty($jsonString)) {
        return null;
    }
    // ทำการ decode ข้อมูล
    $decoded = json_decode($jsonString, true);
    // ตรวจสอบความถูกต้องของไวยากรณ์ JSON หลังจาก decode
    if (json_last_error() !== JSON_ERROR_NONE) {
        // บันทึก Error ลง Error Log ของ Server เพื่อการตรวจสอบที่รวดเร็ว
        error_log("JSON Decode Error: " . json_last_error_msg() . " | Input: " . $jsonString);
        return null;
    }
    return $decoded;
}