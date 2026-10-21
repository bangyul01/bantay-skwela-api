<?php

header("Content-Type: application/json; charset=UTF-8");

$method = $_SERVER["REQUEST_METHOD"];
$path = rtrim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
$path = $path === "" ? "/" : $path;

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

// Temporary in-memory data.
// Data resets whenever the PHP server restarts.
$users = [
    [
        "id" => 1,
        "username" => "admin",
        "password" => password_hash("password123", PASSWORD_DEFAULT),
        "email" => "admin@example.com",
        "phone_number" => "09123456789",
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

// AUTH: REGISTER
if ($path === "/api/register" && $method === "POST") {
    $data = input();

    foreach (["username", "password", "email"] as $field) {
        if (empty($data[$field])) {
            respond([
                "success" => false,
                "message" => "$field is required"
            ], 400);
        }
    }

    foreach ($users as $user) {
        if ($user["username"] === $data["username"]) {
            respond([
                "success" => false,
                "message" => "Username already exists"
            ], 409);
        }

        if ($user["email"] === $data["email"]) {
            respond([
                "success" => false,
                "message" => "Email already exists"
            ], 409);
        }
    }

    $user = [
        "id" => nextId($users),
        "username" => $data["username"],
        "password" => password_hash($data["password"], PASSWORD_DEFAULT),
        "email" => $data["email"],
        "phone_number" => $data["phone_number"] ?? "",
        "is_admin" => false,
        "is_teacher" => false,
        "is_student" => true,
        "is_parent" => false
    ];

    $users[] = $user;

    respond([
        "success" => true,
        "message" => "Registration successful",
        "data" => safeUser($user)
    ], 201);
}

// AUTH: LOGIN
if ($path === "/api/login" && $method === "POST") {
    $data = input();

    foreach ($users as $user) {
        if (
            $user["username"] === ($data["username"] ?? "") &&
            password_verify($data["password"] ?? "", $user["password"])
        ) {
            respond([
                "success" => true,
                "message" => "Login successful",
                "token" => bin2hex(random_bytes(16)),
                "data" => safeUser($user)
            ]);
        }
    }

    respond([
        "success" => false,
        "message" => "Invalid username or password"
    ], 401);
}

// AUTH: LOGOUT (demo only; token is not tracked)
if ($path === "/api/logout" && $method === "POST") {
    respond([
        "success" => true,
        "message" => "Logout successful (demo only)"
    ]);
}

// USERS: GET ALL
if ($path === "/api/users" && $method === "GET") {
    respond([
        "success" => true,
        "data" => safeUsers($users)
    ]);
}

// USERS: GET BY ID
if (preg_match('#^/api/users/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    $index = findIndex($users, $id);

    if ($method === "GET") {
        if ($index < 0) {
            respond(["success" => false, "message" => "User not found"], 404);
        }

        respond(["success" => true, "data" => safeUser($users[$index])]);
    }

    if ($method === "PUT") {
        if ($index < 0) {
            respond(["success" => false, "message" => "User not found"], 404);
        }

        $data = input();

        foreach (["username", "email", "phone_number",
                  "is_admin", "is_teacher", "is_student", "is_parent"] as $field) {
            if (array_key_exists($field, $data)) {
                $users[$index][$field] = $data[$field];
            }
        }

        if (!empty($data["password"])) {
            $users[$index]["password"] =
                password_hash($data["password"], PASSWORD_DEFAULT);
        }

        respond([
            "success" => true,
            "message" => "User updated",
            "data" => safeUser($users[$index])
        ]);
    }

    if ($method === "DELETE") {
        if ($index < 0) {
            respond(["success" => false, "message" => "User not found"], 404);
        }

        array_splice($users, $index, 1);

        respond(["success" => true, "message" => "User deleted"]);
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

// CLASSES
if ($path === "/api/classes") {
    if ($method === "GET") {
        respond(["success" => true, "data" => $classes]);
    }

    if ($method === "POST") {
        $data = input();

        foreach (["subject_id", "user_id", "room_id", "class_datetime"] as $field) {
            if (!isset($data[$field])) {
                respond(["success" => false, "message" => "$field is required"], 400);
            }
        }

        $class = [
            "id" => nextId($classes),
            "subject_id" => (int)$data["subject_id"],
            "user_id" => (int)$data["user_id"],
            "room_id" => (int)$data["room_id"],
            "class_datetime" => $data["class_datetime"],
            "location" => $data["location"] ?? ""
        ];

        $classes[] = $class;

        respond(["success" => true, "message" => "Class created",
                 "data" => $class], 201);
    }
}

if (preg_match('#^/api/classes/(\d+)$#', $path, $m)) {
    $index = findIndex($classes, (int)$m[1]);

    if ($method === "GET") {
        if ($index < 0) respond(["success" => false, "message" => "Class not found"], 404);
        respond(["success" => true, "data" => $classes[$index]]);
    }

    if ($method === "PUT") {
        if ($index < 0) respond(["success" => false, "message" => "Class not found"], 404);

        $data = input();
        foreach (["subject_id", "user_id", "room_id", "class_datetime", "location"] as $field) {
            if (array_key_exists($field, $data)) $classes[$index][$field] = $data[$field];
        }

        respond(["success" => true, "message" => "Class updated",
                 "data" => $classes[$index]]);
    }

    if ($method === "DELETE") {
        if ($index < 0) respond(["success" => false, "message" => "Class not found"], 404);
        array_splice($classes, $index, 1);
        respond(["success" => true, "message" => "Class deleted"]);
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
