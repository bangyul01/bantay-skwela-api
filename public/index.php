<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/database.php';

$method = $_SERVER["REQUEST_METHOD"];
$path = rtrim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
$path = $path === "" ? "/" : $path;
if (
    $path === "/api/class-attendances" ||
    strpos($path, "/api/class-attendances/") === 0
) {
    $path = str_replace(
        "/api/class-attendances",
        "/api/attendances",
        $path
    );
}

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function input() {
    $data = json_decode(file_get_contents("php://input"), true);
    return is_array($data) ? $data : [];
}

function nextId($items) {
    $ids = array_column($items, "id");
    return empty($ids) ? 1 : max($ids) + 1;
}

function findIndex($items, $id) {
    foreach ($items as $index => $item) {
        if ((int)$item["id"] === (int)$id) {
            return $index;
        }
    }
    return -1;
}

function safeUser($user) {
    unset($user["password"]);
    return $user;
}

function safeUsers($users) {
    return array_map("safeUser", array_values($users));
}

function authenticatedUser($pdo) {
    $headers = function_exists("getallheaders")
        ? getallheaders()
        : [];

    $authorization = $_SERVER["HTTP_AUTHORIZATION"]
        ?? $headers["Authorization"]
        ?? $headers["authorization"]
        ?? "";

    if (!preg_match('/Bearer\s+(\S+)/i', $authorization, $matches)) {
        return null;
    }

    $token = $matches[1];

    $stmt = $pdo->prepare(
        "SELECT u.*
         FROM auth_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token = :token
         AND t.expires_at > NOW()
         LIMIT 1"
    );

    $stmt->execute([
        ":token" => $token
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

// Temporary in-memory data.
// Data resets whenever the PHP server restarts.
$users = [
    [
        "id" => 1,
        "username" => "admin",
        "password" => password_hash("password123", PASSWORD_DEFAULT),
        "email" => "admin@example.com",
        "phone_number" => "09123456789",

        "student_number" => null,
        "qr_code" => null,
        "parent_id" => null,
        "section" => null,

        "is_admin" => true,
        "is_teacher" => false,
        "is_student" => false,
        "is_parent" => false
    ]
];

$subjects = [
    ["id" => 1, "name" => "Introduction to Computing",
     "description" => "Basic computing concepts"]
];

$rooms = [
    ["id" => 1, "room_name" => "Room 101"]
];

$classes = [
    [
        "id" => 1,
        "subject_id" => 1,
        "user_id" => 1,
        "room_id" => 1,
        "class_datetime" => "2026-10-21 08:00:00",
        "location" => "Main Building"
    ]
];

$attendances = [
    [
        "id" => 1,
        "class_id" => 1,
        "user_id" => 1,
        "datetime_check_in" => "2026-10-21 08:00:00",
        "status" => "On Time"
    ]
];

// DATABASE CONNECTION TEST
if ($path === "/api/db-test" && $method === "GET") {
    try {
        $stmt = $pdo->query("SELECT current_database() AS database_name");
        $result = $stmt->fetch();

        respond([
            "success" => true,
            "message" => "Database connection successful",
            "database" => $result["database_name"]
        ]);

    } catch (PDOException $e) {
        respond([
            "success" => false,
            "message" => "Database query failed"
        ], 500);
    }
}

// API STATUS
if ($path === "/" && $method === "GET") {
    respond([
        "success" => true,
        "message" => "Bantay Skwela API is running"
    ]);
}

if ($path === "/api/test" && $method === "GET") {
    respond([
        "success" => true,
        "message" => "API test successful"
    ]);
}

// AUTHENTICATION TEST
if ($path === "/api/auth-test" && $method === "GET") {
    $user = authenticatedUser($pdo);

    if (!$user) {
        respond([
            "success" => false,
            "message" => "Unauthorized. Invalid or expired token."
        ], 401);
    }

    respond([
        "success" => true,
        "message" => "Authentication successful",
        "data" => [
            "id" => $user["id"],
            "username" => $user["username"],
            "role" => !empty($user["is_teacher"])
                ? "teacher"
                : "other"
        ]
    ]);
}


// AUTH: REGISTER
if ($path === "/api/register" && $method === "POST") {
    $data = input();

    $username = trim($data["username"] ?? "");
    $email = trim($data["email"] ?? "");
    $password = $data["password"] ?? "";

    if ($username === "" || $email === "" || $password === "") {
        respond([
            "success" => false,
            "message" => "Username, email, and password are required"
        ], 400);
    }

    try {
        $check = $pdo->prepare(
            "SELECT id FROM users
             WHERE username = :username OR email = :email"
        );

        $check->execute([
            ":username" => $username,
            ":email" => $email
        ]);

        if ($check->fetch()) {
            respond([
                "success" => false,
                "message" => "Username or email already exists"
            ], 409);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO users (
                username, password, email, phone_number,
                student_number, qr_code, parent_id, section,
                is_admin, is_teacher, is_student, is_parent
            ) VALUES (
                :username, :password, :email, :phone_number,
                :student_number, :qr_code, :parent_id, :section,
                FALSE, FALSE, TRUE, FALSE
            )
            RETURNING *"
        );

        $stmt->execute([
            ":username" => $username,
            ":password" => password_hash($password, PASSWORD_DEFAULT),
            ":email" => $email,
            ":phone_number" => $data["phone_number"] ?? "",
            ":student_number" => $data["student_number"] ?? null,
            ":qr_code" => $data["qr_code"] ?? null,
            ":parent_id" => !empty($data["parent_id"])
                ? $data["parent_id"] : null,
            ":section" => $data["section"] ?? null
        ]);

        $user = $stmt->fetch();

        respond([
            "success" => true,
            "message" => "Registration successful",
            "data" => safeUser($user)
        ], 201);

    } catch (PDOException $e) {
        error_log("Registration error: " . $e->getMessage());

        respond([
            "success" => false,
            "message" => "Registration failed"
        ], 500);
    }
}


// AUTH: LOGIN
if (
    in_array($path, ["/api/login", "/api/auth/login"], true)
    && $method === "POST"
) {
    $data = input();

    $identifier = trim($data["username"] ?? $data["email"] ?? "");
    $password = $data["password"] ?? "";

    if ($identifier === "" || $password === "") {
        respond([
            "success" => false,
            "message" => "Username/email and password are required"
        ], 400);
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM users
             WHERE username = :username OR email = :email
             LIMIT 1"
        );

        $stmt->execute([
            ":username" => $identifier,
            ":email" => $identifier
        ]);

        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user["password"])) {
            respond([
                "success" => false,
                "message" => "Invalid username or password"
            ], 401);
        }

        $userData = safeUser($user);
        $userData["phone"] = $user["phone_number"] ?? "";

        if (!empty($user["is_admin"])) {
            $userData["role"] = "admin";
        } elseif (!empty($user["is_teacher"])) {
            $userData["role"] = "teacher";
        } elseif (!empty($user["is_parent"])) {
            $userData["role"] = "parent";
        } else {
            $userData["role"] = "student";
        }

        $token = bin2hex(random_bytes(32));

$tokenStmt = $pdo->prepare(
    "INSERT INTO auth_tokens (user_id, token, expires_at)
     VALUES (:user_id, :token, NOW() + INTERVAL '7 days')"
);

$tokenStmt->execute([
    ':user_id' => $user['id'],
    ':token' => $token
]);

respond([
    "success" => true,
    "message" => "Login successful",
    "token" => $token,
    "data" => $userData
]);

    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());

        respond([
            "success" => false,
            "message" => "Login failed"
        ], 500);
    }
}

// AUTH: LOGOUT (demo only; token is not tracked)
if (
    in_array($path, ["/api/logout", "/api/auth/logout"], true)
    && $method === "POST"
) {
    respond([
        "success" => true,
        "message" => "Logout successful (demo only)"
    ]);
}


// USERS: GET ALL
if ($path === "/api/users" && $method === "GET") {
    try {
        $stmt = $pdo->query(
            "SELECT * FROM users ORDER BY id ASC"
        );

        $usersFromDb = $stmt->fetchAll(PDO::FETCH_ASSOC);

        respond([
            "success" => true,
            "data" => safeUsers($usersFromDb)
        ]);

    } catch (PDOException $e) {
        error_log("Get users error: " . $e->getMessage());

        respond([
            "success" => false,
            "message" => "Failed to retrieve users"
        ], 500);
    }
}

// USERS: GET, UPDATE, DELETE BY ID
if (preg_match('#^/api/users/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];

    // GET USER
    if ($method === "GET") {
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM users WHERE id = :id"
            );

            $stmt->execute([":id" => $id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                respond([
                    "success" => false,
                    "message" => "User not found"
                ], 404);
            }

            $user["phone"] = $user["phone_number"] ?? "";

            respond([
                "success" => true,
                "data" => safeUser($user)
            ]);

        } catch (PDOException $e) {
            error_log("Get user error: " . $e->getMessage());

            respond([
                "success" => false,
                "message" => "Failed to retrieve user"
            ], 500);
        }
    }

    // UPDATE USER
    if ($method === "PUT") {
        $data = input();

        $allowedFields = [
            "username",
            "email",
            "phone_number",
            "student_number",
            "qr_code",
            "parent_id",
            "section",
            "is_admin",
            "is_teacher",
            "is_student",
            "is_parent"
        ];

        // Accept Android field aliases
        if (array_key_exists("name", $data) &&
            !array_key_exists("username", $data)) {
            $data["username"] = $data["name"];
        }

        if (array_key_exists("phone", $data) &&
            !array_key_exists("phone_number", $data)) {
            $data["phone_number"] = $data["phone"];
        }

        $updates = [];
        $params = [":id" => $id];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (!empty($data["password"])) {
            $updates[] = "password = :password";
            $params[":password"] = password_hash(
                $data["password"],
                PASSWORD_DEFAULT
            );
        }

        if (empty($updates)) {
            respond([
                "success" => false,
                "message" => "No fields provided for update"
            ], 400);
        }

        $updates[] = "updated_at = NOW()";

        try {
            $sql = "UPDATE users SET " .
                   implode(", ", $updates) .
                   " WHERE id = :id RETURNING *";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$updatedUser) {
                respond([
                    "success" => false,
                    "message" => "User not found"
                ], 404);
            }

            $updatedUser["phone"] =
                $updatedUser["phone_number"] ?? "";

            respond([
                "success" => true,
                "message" => "User updated successfully",
                "data" => safeUser($updatedUser)
            ]);

        } catch (PDOException $e) {
    error_log("Update user error: " . $e->getMessage());

    respond([
        "success" => false,
        "message" => "Failed to update user"
    ], 500);
}
    }

    // DELETE USER
    if ($method === "DELETE") {
        try {
            $stmt = $pdo->prepare(
                "DELETE FROM users WHERE id = :id RETURNING id"
            );

            $stmt->execute([":id" => $id]);
            $deletedUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$deletedUser) {
                respond([
                    "success" => false,
                    "message" => "User not found"
                ], 404);
            }

            respond([
                "success" => true,
                "message" => "User deleted successfully"
            ]);

        } catch (PDOException $e) {
            error_log("Delete user error: " . $e->getMessage());

            respond([
                "success" => false,
                "message" => "Failed to delete user"
            ], 500);
        }
    }
}

// SUBJECTS
if ($path === "/api/subjects") {
    if ($method === "GET") {
        respond(["success" => true, "data" => $subjects]);
    }

    if ($method === "POST") {
        $data = input();

        if (empty($data["name"])) {
            respond(["success" => false, "message" => "Subject name is required"], 400);
        }

        $subject = [
            "id" => nextId($subjects),
            "name" => $data["name"],
            "description" => $data["description"] ?? ""
        ];

        $subjects[] = $subject;

        respond(["success" => true, "message" => "Subject created",
                 "data" => $subject], 201);
    }
}

if (preg_match('#^/api/subjects/(\d+)$#', $path, $m)) {
    $index = findIndex($subjects, (int)$m[1]);

    if ($method === "GET") {
        if ($index < 0) respond(["success" => false, "message" => "Subject not found"], 404);
        respond(["success" => true, "data" => $subjects[$index]]);
    }

    if ($method === "PUT") {
        if ($index < 0) respond(["success" => false, "message" => "Subject not found"], 404);

        $data = input();
        foreach (["name", "description"] as $field) {
            if (array_key_exists($field, $data)) $subjects[$index][$field] = $data[$field];
        }

        respond(["success" => true, "message" => "Subject updated",
                 "data" => $subjects[$index]]);
    }

    if ($method === "DELETE") {
        if ($index < 0) respond(["success" => false, "message" => "Subject not found"], 404);
        array_splice($subjects, $index, 1);
        respond(["success" => true, "message" => "Subject deleted"]);
    }
}

// ROOMS
if ($path === "/api/rooms") {
    if ($method === "GET") {
        respond(["success" => true, "data" => $rooms]);
    }

    if ($method === "POST") {
        $data = input();

        if (empty($data["room_name"])) {
            respond(["success" => false, "message" => "Room name is required"], 400);
        }

        $room = [
            "id" => nextId($rooms),
            "room_name" => $data["room_name"]
        ];

        $rooms[] = $room;

        respond(["success" => true, "message" => "Room created",
                 "data" => $room], 201);
    }
}

if (preg_match('#^/api/rooms/(\d+)$#', $path, $m)) {
    $index = findIndex($rooms, (int)$m[1]);

    if ($method === "GET") {
        if ($index < 0) respond(["success" => false, "message" => "Room not found"], 404);
        respond(["success" => true, "data" => $rooms[$index]]);
    }

    if ($method === "PUT") {
        if ($index < 0) respond(["success" => false, "message" => "Room not found"], 404);

        $data = input();
        if (isset($data["room_name"])) $rooms[$index]["room_name"] = $data["room_name"];

        respond(["success" => true, "message" => "Room updated",
                 "data" => $rooms[$index]]);
    }

    if ($method === "DELETE") {
        if ($index < 0) respond(["success" => false, "message" => "Room not found"], 404);
        array_splice($rooms, $index, 1);
        respond(["success" => true, "message" => "Room deleted"]);
    }
}

// STUDENTS: GET ENROLLED CLASS SCHEDULE
if (preg_match('#^/api/students/(\d+)/schedule$#', $path, $m) && $method === "GET") {

    $studentId = (int)$m[1];

    try {
        $stmt = $pdo->prepare(
            "SELECT
                c.id,
                s.name AS class_name,
                s.name AS subject_name,
                c.user_id AS teacher_id,
                u.username AS teacher_name,
                r.room_name,
                TO_CHAR(c.class_datetime AT TIME ZONE 'Asia/Manila', 'FMDay') AS day_of_week,
                TO_CHAR(c.class_datetime AT TIME ZONE 'Asia/Manila', 'HH12:MI AM') AS start_time,
                NULL AS end_time
            FROM class_enrollments e
            JOIN classes c ON e.class_id = c.id
            LEFT JOIN subjects s ON c.subject_id = s.id
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN rooms r ON c.room_id = r.id
            WHERE e.student_id = :student_id
            ORDER BY c.class_datetime ASC"
        );

        $stmt->execute([
            ":student_id" => $studentId
        ]);

        respond([
            "success" => true,
            "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

    } catch (PDOException $e) {
        error_log("Get student schedule error: " . $e->getMessage());

        respond([
            "success" => false,
            "message" => "Failed to retrieve student schedule"
        ], 500);
    }
}


// TEACHER: GET ASSIGNED CLASSES
if ($path === "/api/teachers/classes" && $method === "GET") {

    // Verify teacher authentication
    $teacher = authenticatedUser($pdo);

    if (!$teacher) {
        respond([
            "success" => false,
            "message" => "Unauthorized"
        ], 401);
    }

    if (empty($teacher["is_teacher"])) {
        respond([
            "success" => false,
            "message" => "Only teachers can access assigned classes"
        ], 403);
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT
                c.id,
                s.name AS class_name,
                s.name AS subject_name,
                c.user_id AS teacher_id,
                u.username AS teacher_name,
                r.room_name,
                TO_CHAR(
                    c.class_datetime AT TIME ZONE 'Asia/Manila',
                    'FMDay'
                ) AS day_of_week,
                TO_CHAR(
                    c.class_datetime AT TIME ZONE 'Asia/Manila',
                    'HH12:MI AM'
                ) AS start_time,
                NULL AS end_time
            FROM classes c
            LEFT JOIN subjects s ON c.subject_id = s.id
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN rooms r ON c.room_id = r.id
            WHERE c.user_id = :teacher_id
            ORDER BY c.class_datetime ASC"
        );

        $stmt->execute([
            ":teacher_id" => $teacher["id"]
        ]);

        respond([
            "success" => true,
            "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

    } catch (PDOException $e) {
        error_log("Get teacher classes error: " . $e->getMessage());

        respond([
            "success" => false,
            "message" => "Failed to retrieve teacher classes"
        ], 500);
    }
}


// ENROLLMENTS: GET STUDENTS IN A CLASS
if (preg_match('#^/api/classes/(\d+)/enrollments$#', $path, $matches)
    && $method === "GET") {

    $teacher = authenticatedUser($pdo);

    if (!$teacher) {
        respond([
            "success" => false,
            "message" => "Unauthorized"
        ], 401);
    }

    if (empty($teacher["is_teacher"])) {
        respond([
            "success" => false,
            "message" => "Only teachers can view enrollments"
        ], 403);
    }

    $classId = (int) $matches[1];

    try {
        // Verify class ownership
        $check = $pdo->prepare(
            "SELECT id FROM classes
             WHERE id = :class_id
             AND user_id = :teacher_id"
        );

        $check->execute([
            ":class_id" => $classId,
            ":teacher_id" => $teacher["id"]
        ]);

        if (!$check->fetch(PDO::FETCH_ASSOC)) {
            respond([
                "success" => false,
                "message" => "Class not found or access denied"
            ], 403);
        }

        // Retrieve enrolled students
        $stmt = $pdo->prepare(
            "SELECT
                u.id AS student_id,
                u.username AS student_name
             FROM class_enrollments ce
             INNER JOIN users u
                ON ce.student_id = u.id
             WHERE ce.class_id = :class_id
             ORDER BY u.username ASC"
        );

        $stmt->execute([
            ":class_id" => $classId
        ]);

        respond([
            "success" => true,
            "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

    } catch (PDOException $e) {
        error_log("Get enrollments error: " . $e->getMessage());

        respond([
            "success" => false,
            "message" => "Failed to retrieve enrollments"
        ], 500);
    }
}


 // ENROLLMENTS: ADD STUDENT TO CLASS
if (preg_match('#^/api/classes/(\d+)/enrollments$#', $path, $matches)
    && $method === "POST") {

    $teacher = authenticatedUser($pdo);

    if (!$teacher) {
        respond([
            "success" => false,
            "message" => "Unauthorized"
        ], 401);
    }

    if (empty($teacher["is_teacher"])) {
        respond([
            "success" => false,
            "message" => "Only teachers can enroll students"
        ], 403);
    }

    $classId = (int) $matches[1];
    $data = input();
    $studentId = (int) ($data["student_id"] ?? 0);

    if ($studentId <= 0) {
        respond([
            "success" => false,
            "message" => "Valid student_id is required"
        ], 400);
    }

    try {
        // Verify that the teacher owns the class
        $checkClass = $pdo->prepare(
            "SELECT id FROM classes
             WHERE id = :class_id
             AND user_id = :teacher_id"
        );

        $checkClass->execute([
            ":class_id" => $classId,
            ":teacher_id" => $teacher["id"]
        ]);

        if (!$checkClass->fetch(PDO::FETCH_ASSOC)) {
            respond([
                "success" => false,
                "message" => "Class not found or access denied"
            ], 403);
        }

        // Verify that the selected user is a student
        $checkStudent = $pdo->prepare(
            "SELECT id FROM users
             WHERE id = :student_id
             AND is_student = TRUE"
        );

        $checkStudent->execute([
            ":student_id" => $studentId
        ]);

        if (!$checkStudent->fetch(PDO::FETCH_ASSOC)) {
            respond([
                "success" => false,
                "message" => "Student not found"
            ], 404);
        }

        // Check if student is already enrolled
        $checkEnrollment = $pdo->prepare(
            "SELECT 1 FROM class_enrollments
             WHERE class_id = :class_id
             AND student_id = :student_id"
        );

        $checkEnrollment->execute([
            ":class_id" => $classId,
            ":student_id" => $studentId
        ]);

        if ($checkEnrollment->fetch(PDO::FETCH_ASSOC)) {
            respond([
                "success" => false,
                "message" => "Student is already enrolled"
            ], 409);
        }

        // Insert enrollment
        $stmt = $pdo->prepare(
            "INSERT INTO class_enrollments
             (class_id, student_id)
             VALUES (:class_id, :student_id)"
        );

        $stmt->execute([
            ":class_id" => $classId,
            ":student_id" => $studentId
        ]);

        respond([
            "success" => true,
            "message" => "Student enrolled successfully"
        ], 201);

    } catch (PDOException $e) {
        error_log("Enroll student error: " . $e->getMessage());

        respond([
            "success" => false,
            "message" => "Failed to enroll student"
        ], 500);
    }
}


// TEACHER: GET CLASS ATTENDANCE
if (preg_match('#^/api/classes/(\d+)/attendances$#', $path, $matches)
    && $method === "GET") {

    $teacher = authenticatedUser($pdo);

    if (!$teacher) {
        respond([
            "success" => false,
            "message" => "Unauthorized"
        ], 401);
    }

    if (empty($teacher["is_teacher"])) {
        respond([
            "success" => false,
            "message" => "Only teachers can access class attendance"
        ], 403);
    }

    $classId = (int) $matches[1];

    try {
        // Verify class ownership
        $check = $pdo->prepare(
            "SELECT id FROM classes
             WHERE id = :class_id
             AND user_id = :teacher_id"
        );

        $check->execute([
            ":class_id" => $classId,
            ":teacher_id" => $teacher["id"]
        ]);

        if (!$check->fetch(PDO::FETCH_ASSOC)) {
            respond([
                "success" => false,
                "message" => "Class not found or access denied"
            ], 403);
        }

        // Get enrolled students and their attendance
        $stmt = $pdo->prepare(
            "SELECT
                u.id AS student_id,
                u.username AS student_name,
                COALESCE(ca.status, 'Absent') AS status,
                ca.datetime_check_in
             FROM class_enrollments ce
             INNER JOIN users u
                ON ce.student_id = u.id
             LEFT JOIN class_attendances ca
                ON ca.class_id = ce.class_id
                AND ca.user_id = ce.student_id
             WHERE ce.class_id = :class_id
             ORDER BY u.username ASC"
        );

        $stmt->execute([
            ":class_id" => $classId
        ]);

        respond([
            "success" => true,
            "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

    } catch (PDOException $e) {
        error_log("Get class attendance error: " . $e->getMessage());

        respond([
            "success" => false,
            "message" => "Failed to retrieve class attendance"
        ], 500);
    }
}

// CLASSES: GET ALL
if ($path === "/api/classes" && $method === "GET") {
    try {
        $stmt = $pdo->query(
            "SELECT
                c.id,
                s.name AS class_name,
                s.name AS subject_name,
                c.user_id AS teacher_id,
                u.username AS teacher_name,
                r.room_name,
                TO_CHAR(c.class_datetime, 'FMDay') AS day_of_week,
                TO_CHAR(c.class_datetime, 'HH12:MI AM') AS start_time,
                NULL AS end_time
            FROM classes c
            LEFT JOIN subjects s ON c.subject_id = s.id
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN rooms r ON c.room_id = r.id
            ORDER BY c.class_datetime ASC"
        );

        respond([
            "success" => true,
            "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

    } catch (PDOException $e) {
        error_log("Get classes error: " . $e->getMessage());

        respond([
            "success" => false,
            "message" => "Failed to retrieve classes"
        ], 500);
    }
}

// CLASSES: CREATE
if ($path === "/api/classes" && $method === "POST") {
    $data = input();

    foreach (["subject_id", "user_id", "room_id", "class_datetime"] as $field) {
        if (!isset($data[$field]) || $data[$field] === "") {
            respond([
                "success" => false,
                "message" => "$field is required"
            ], 400);
        }
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO classes (
                subject_id, user_id, room_id, class_datetime, location
            ) VALUES (
                :subject_id, :user_id, :room_id, :class_datetime, :location
            )
            RETURNING *"
        );

        $stmt->execute([
            ":subject_id" => $data["subject_id"],
            ":user_id" => $data["user_id"],
            ":room_id" => $data["room_id"],
            ":class_datetime" => $data["class_datetime"],
            ":location" => $data["location"] ?? null
        ]);

        respond([
            "success" => true,
            "message" => "Class created successfully",
            "data" => $stmt->fetch(PDO::FETCH_ASSOC)
        ], 201);

    } catch (PDOException $e) {
        error_log("Create class error: " . $e->getMessage());

        respond([
            "success" => false,
            "message" => "Failed to create class"
        ], 500);
    }
}

// CLASSES: GET, UPDATE, DELETE BY ID
if (preg_match('#^/api/classes/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];

    // GET CLASS BY ID
    if ($method === "GET") {
        try {
            $stmt = $pdo->prepare(
                "SELECT
                    c.id,
                    c.subject_id,
                    c.user_id,
                    c.room_id,
                    c.class_datetime,
                    c.location,
                    s.name AS subject_name,
                    u.username AS teacher_name,
                    r.room_name,
                    TO_CHAR(c.class_datetime, 'FMDay') AS day_of_week,
                    TO_CHAR(c.class_datetime, 'HH12:MI AM') AS start_time
                FROM classes c
                LEFT JOIN subjects s ON c.subject_id = s.id
                LEFT JOIN users u ON c.user_id = u.id
                LEFT JOIN rooms r ON c.room_id = r.id
                WHERE c.id = :id"
            );

            $stmt->execute([":id" => $id]);
            $class = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$class) {
                respond([
                    "success" => false,
                    "message" => "Class not found"
                ], 404);
            }

            respond([
                "success" => true,
                "data" => $class
            ]);

        } catch (PDOException $e) {
            error_log("Get class error: " . $e->getMessage());

            respond([
                "success" => false,
                "message" => "Failed to retrieve class"
            ], 500);
        }
    }

    // UPDATE CLASS
    if ($method === "PUT") {
        $data = input();

        $allowedFields = [
            "subject_id",
            "user_id",
            "room_id",
            "class_datetime",
            "location"
        ];

        $updates = [];
        $params = [":id" => $id];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($updates)) {
            respond([
                "success" => false,
                "message" => "No fields provided for update"
            ], 400);
        }

        try {
            $sql = "UPDATE classes SET " .
                   implode(", ", $updates) .
                   " WHERE id = :id RETURNING *";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $class = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$class) {
                respond([
                    "success" => false,
                    "message" => "Class not found"
                ], 404);
            }

            respond([
                "success" => true,
                "message" => "Class updated successfully",
                "data" => $class
            ]);

        } catch (PDOException $e) {
            error_log("Update class error: " . $e->getMessage());

            respond([
                "success" => false,
                "message" => "Failed to update class"
            ], 500);
        }
    }

    // DELETE CLASS
    if ($method === "DELETE") {
        try {
            $stmt = $pdo->prepare(
                "DELETE FROM classes WHERE id = :id RETURNING id"
            );

            $stmt->execute([":id" => $id]);

            if (!$stmt->fetch()) {
                respond([
                    "success" => false,
                    "message" => "Class not found"
                ], 404);
            }

            respond([
                "success" => true,
                "message" => "Class deleted successfully"
            ]);

        } catch (PDOException $e) {
            error_log("Delete class error: " . $e->getMessage());

            respond([
                "success" => false,
                "message" => "Failed to delete class"
            ], 500);
        }
    }
}


// TEACHER: GENERATE CLASS QR CODE
if (preg_match('#^/api/classes/(\d+)/qr$#', $path, $m)
    && $method === "POST") {

    $classId = (int)$m[1];

    // Verify teacher authentication
    $teacher = authenticatedUser($pdo);

    if (!$teacher) {
        respond([
            "success" => false,
            "message" => "Unauthorized"
        ], 401);
    }

    if (empty($teacher["is_teacher"])) {
        respond([
            "success" => false,
            "message" => "Only teachers can generate QR codes"
        ], 403);
    }

    try {
        // Check whether this class belongs to the teacher
        $classStmt = $pdo->prepare(
            "SELECT id FROM classes
             WHERE id = :class_id
             AND user_id = :teacher_id"
        );

        $classStmt->execute([
            ":class_id" => $classId,
            ":teacher_id" => $teacher["id"]
        ]);

        if (!$classStmt->fetch()) {
            respond([
                "success" => false,
                "message" => "Class not found or not assigned to this teacher"
            ], 404);
        }

        // Deactivate previous QR sessions for this class
        $deactivateStmt = $pdo->prepare(
            "UPDATE qr_sessions
             SET is_active = FALSE
             WHERE class_id = :class_id
             AND is_active = TRUE"
        );

        $deactivateStmt->execute([
            ":class_id" => $classId
        ]);

        // Generate a new QR session
        
$qrStmt = $pdo->prepare(
    "INSERT INTO qr_sessions (
        class_id,
        teacher_id,
        expires_at,
        is_active
    ) VALUES (
        :class_id,
        :teacher_id,
        NOW() + INTERVAL '15 minutes',
        TRUE
    )
    RETURNING id, class_id, teacher_id, token, expires_at"
);


        $qrStmt->execute([
            ":class_id" => $classId,
            ":teacher_id" => $teacher["id"]
        ]);

        $qrSession = $qrStmt->fetch(PDO::FETCH_ASSOC);

        respond([
            "success" => true,
            "message" => "QR code generated successfully",
            "data" => [
                "id" => $qrSession["id"],
                "class_id" => $qrSession["class_id"],
                "teacher_id" => $qrSession["teacher_id"],
                "token" => $qrSession["token"],
                "qr_data" => $qrSession["token"],
                "expires_at" => $qrSession["expires_at"]
            ]
        ], 201);

    } catch (PDOException $e) {
        error_log("Generate QR error: " . $e->getMessage());

        respond([
            "success" => false,
            "message" => "Failed to generate QR code"
        ], 500);
    }
}


// STUDENT: SCAN CLASS QR CODE
if ($path === "/api/attendances/scan" && $method === "POST") {

    // Verify student authentication
    $student = authenticatedUser($pdo);

    if (!$student) {
        respond([
            "success" => false,
            "message" => "Unauthorized"
        ], 401);
    }

    if (empty($student["is_student"])) {
        respond([
            "success" => false,
            "message" => "Only students can scan attendance QR codes"
        ], 403);
    }

    // Get QR token from request body
    $data = input();
    $qrToken = trim($data["qr_token"] ?? "");

    if ($qrToken === "") {
        respond([
            "success" => false,
            "message" => "QR token is required"
        ], 400);
    }

    try {
        // Find an active and unexpired QR session
        $stmt = $pdo->prepare(
            "SELECT
                q.id AS qr_session_id,
                q.class_id,
                c.class_datetime,
                s.name AS class_name
             FROM qr_sessions q
             JOIN classes c ON c.id = q.class_id
             JOIN subjects s ON s.id = c.subject_id
             WHERE q.token = :token
             AND q.is_active = TRUE
             AND q.expires_at > NOW()
             LIMIT 1"
        );

        $stmt->execute([
            ":token" => $qrToken
        ]);

        $qrSession = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$qrSession) {
            respond([
                "success" => false,
                "message" => "Invalid or expired QR code"
            ], 400);
        }

        $classId = (int)$qrSession["class_id"];
        $studentId = (int)$student["id"];

        // Check if student is enrolled in this class
        $enrollmentStmt = $pdo->prepare(
            "SELECT id FROM class_enrollments
             WHERE class_id = :class_id
             AND student_id = :student_id
             LIMIT 1"
        );

        $enrollmentStmt->execute([
            ":class_id" => $classId,
            ":student_id" => $studentId
        ]);

        if (!$enrollmentStmt->fetch()) {
            respond([
                "success" => false,
                "message" => "You are not enrolled in this class"
            ], 403);
        }

        // Determine attendance status using the 15-minute grace period
        $statusStmt = $pdo->prepare(
            "SELECT CASE
                WHEN NOW() <= CAST(:class_datetime AS TIMESTAMPTZ)
                    + INTERVAL '15 minutes'
                THEN 'On Time'
                ELSE 'Late'
             END AS attendance_status"
        );

        $statusStmt->execute([
            ":class_datetime" => $qrSession["class_datetime"]
        ]);

        $status = $statusStmt->fetchColumn();

        // Record attendance
        $insertStmt = $pdo->prepare(
            "INSERT INTO class_attendances (
                class_id,
                user_id,
                datetime_check_in,
                status
            ) VALUES (
                :class_id,
                :user_id,
                NOW(),
                :status
            )
            RETURNING id, datetime_check_in"
        );

        $insertStmt->execute([
            ":class_id" => $classId,
            ":user_id" => $studentId,
            ":status" => $status
        ]);

        $attendance = $insertStmt->fetch(PDO::FETCH_ASSOC);

        respond([
            "success" => true,
            "message" => "Attendance recorded successfully",
            "data" => [
                "attendance_id" => $attendance["id"],
                "class_id" => $classId,
                "class_name" => $qrSession["class_name"],
                "student_id" => $studentId,
                "status" => $status,
                "datetime_check_in" => $attendance["datetime_check_in"]
            ]
        ], 201);

    } catch (PDOException $e) {

        // Prevent duplicate attendance records
        if ($e->getCode() === "23505") {
            respond([
                "success" => false,
                "message" => "Attendance has already been recorded for this class"
            ], 409);
        }

        error_log("QR attendance error: " . $e->getMessage());

        respond([
            "success" => false,
            "message" => "Failed to record attendance"
        ], 500);
    }
}


// ATTENDANCES: GET ALL
if ($path === "/api/attendances" && $method === "GET") {
    respond(["success" => true, "data" => $attendances]);
}

// ATTENDANCES: FILTER BY CLASS
if ($path === "/api/attendances/class" && $method === "GET") {
    $classId = $_GET["class_id"] ?? null;

    if ($classId === null) {
        respond(["success" => false, "message" => "class_id query parameter is required"], 400);
    }

    $result = array_values(array_filter($attendances, function ($a) use ($classId) {
        return (int)$a["class_id"] === (int)$classId;
    }));

    respond(["success" => true, "data" => $result]);
}

// ATTENDANCES: FILTER BY USER
if ($path === "/api/attendances/user" && $method === "GET") {
    $userId = $_GET["user_id"] ?? null;

    if ($userId === null) {
        respond(["success" => false, "message" => "user_id query parameter is required"], 400);
    }

    $result = array_values(array_filter($attendances, function ($a) use ($userId) {
        return (int)$a["user_id"] === (int)$userId;
    }));

    respond(["success" => true, "data" => $result]);
}

// ATTENDANCES: GET, UPDATE, DELETE BY ID
if (preg_match('#^/api/attendances/(\d+)$#', $path, $m)) {
    $index = findIndex($attendances, (int)$m[1]);

    if ($method === "GET") {
        if ($index < 0) respond(["success" => false, "message" => "Attendance not found"], 404);
        respond(["success" => true, "data" => $attendances[$index]]);
    }

    if ($method === "PUT") {
        if ($index < 0) respond(["success" => false, "message" => "Attendance not found"], 404);

        $data = input();
        foreach (["class_id", "user_id", "datetime_check_in", "status"] as $field) {
            if (array_key_exists($field, $data)) $attendances[$index][$field] = $data[$field];
        }

        respond(["success" => true, "message" => "Attendance updated",
                 "data" => $attendances[$index]]);
    }

    if ($method === "DELETE") {
        if ($index < 0) respond(["success" => false, "message" => "Attendance not found"], 404);
        array_splice($attendances, $index, 1);
        respond(["success" => true, "message" => "Attendance deleted"]);
    }
}

// ATTENDANCES: CREATE CHECK-IN
if ($path === "/api/attendances" && $method === "POST") {
    $data = input();

    foreach (["class_id", "user_id"] as $field) {
        if (!isset($data[$field])) {
            respond(["success" => false, "message" => "$field is required"], 400);
        }
    }

    $status = $data["status"] ?? "On Time";
    if (!in_array($status, ["On Time", "Late", "Absent"], true)) {
        respond(["success" => false, "message" => "Invalid attendance status"], 400);
    }

    $attendance = [
        "id" => nextId($attendances),
        "class_id" => (int)$data["class_id"],
        "user_id" => (int)$data["user_id"],
        "datetime_check_in" => $data["datetime_check_in"] ?? date("Y-m-d H:i:s"),
        "status" => $status
    ];

    $attendances[] = $attendance;

    respond(["success" => true, "message" => "Attendance created",
             "data" => $attendance], 201);
}

// UNKNOWN ENDPOINT
respond([
    "success" => false,
    "message" => "Endpoint not found",
    "method" => $method,
    "path" => $path
], 404);
