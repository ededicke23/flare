<?php
/*
Flare, a fully featured and easy to use crew centre, designed for Infinite Flight.
Copyright (C) 2020  Lucas Rebato

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

require_once './core/init.php';
header('Content-Type: application/json');

abstract class AuthType {
    const NoAuth = 0;
    const Session = 1; // All scopes
    const Cookie = 2; // Reserved for Future Use, All Scopes
    const ApiKey = 3; // Read Only
    const BasicHttp = 4; // All Scopes
}

abstract class ErrorCode {
    const NoError = 0;
    const Unauthorized = 1;
    const NotFound = 2;
    const AccessDenied = 3;
    const InternalServerError = 4;
    const CallsignNotValid = 5;
    const CallsignTaken = 6;
    const MultiplierNotFound = 9;
    const RankNotSufficent = 10;
    const VaNotGold = 11;
    const MethodNotAllowed = 12;
}

function unauthorized() {
    http_response_code(401);
    echo Json::encode([
        "status" => ErrorCode::Unauthorized,
        "result" => null
    ]);
    die();
}

function internalError() {
    http_response_code(500);
    echo Json::encode([
        "status" => ErrorCode::InternalServerError,
        "result" => null
    ]);
    die();
}

function badReq($status) {
    http_response_code(400);
    echo Json::encode([
        "status" => $status,
        "result" => null
    ]);
    die();
}

function notFound() {
    http_response_code(404);
    echo Json::encode([
        "status" => ErrorCode::NotFound,
        "result" => null
    ]);
    die();
}

function accessDenied() {
    http_response_code(403);
    echo Json::encode([
        "status" => ErrorCode::AccessDenied,
        "result" => null
    ]);
    die();
}

$user = new User();

$_authType = AuthType::NoAuth;
$_apiUser = new stdClass;
$_headers = getallheaders();

if ($user->isLoggedIn()) {
    // Session Auth
    $_authType = AuthType::Session;
    $_apiUser = $user->data();
} elseif (array_key_exists('Authorization', $_headers) && explode(' ', $_headers['Authorization'])[0] == 'Bearer') {
    // Bearer Auth (Header)
    $check = Api::processKey(explode(' ', $_headers['Authorization'])[1]);
    if ($check === FALSE) {
        unauthorized();
    }

    $_authType = AuthType::ApiKey;
    $_apiUser = $check;
} elseif (array_key_exists('apikey', $_GET)) {
    // Bearer Auth (Query String)
    $check = Api::processKey($_GET['apikey']);
    if ($check === FALSE) {
        unauthorized();
    }

    $_authType = AuthType::ApiKey;
    $_apiUser = $check;
} elseif (array_key_exists('Authorization', $_headers) && explode(' ', $_headers['Authorization'])[0] == 'Basic') {
    // Basic HTTP Auth
    $check = Api::processBasic(explode(' ', $_headers['Authorization'])[1]);
    if ($check === FALSE) {
        unauthorized();
    }

    $_authType = AuthType::BasicHttp;
    $_apiUser = $check;
    $pass = explode(':', base64_decode(explode(' ', $_headers['Authorization'])[1]))[1];
    // If for some reason this fails, return a 500
    if (!$user->login($_apiUser->email, $pass)) {
        internalError();
    }
} else {
    unauthorized();
}

// Not Found
Router::pathNotFound('notFound');

// Method Not Allowed
Router::methodNotAllowed(function() {
    http_response_code(405);
    echo Json::encode([
        "status" => ErrorCode::MethodNotAllowed,
        "result" => null
    ]);
    die();
});

// View All PIREPs for User
Router::add('/pireps', function() {
    global $user, $_apiUser;
    $res = [
        "status" => ErrorCode::NoError,
        "result" => $user->fetchPireps($_apiUser->id)->results(),
    ];

    $i = 0;
    foreach ($res["result"] as $p) {
        $p = (array)$p;
        foreach ($p as $key => $val) {
            if (is_numeric($val) && $key != 'flightnum') {
                $res["result"][$i]->$key = intval($val);
            }
        }
        unset($res["result"][$i]->pilotid);
        $i++;
    }

    echo Json::encode($res);
});

// View Specific PIREP
Router::add('/pireps/([0-9]+)', function($pirepId) {
    global $_apiUser;
    
    $pirep = Pirep::find($pirepId, $_apiUser->id);
    if ($pirep === FALSE) {
        notFound();
    }

    $pirep = (array)$pirep;

    unset($pirep['pilotid']);
    $pirep['id'] = intval($pirep['id']);
    $pirep['flighttime'] = intval($pirep['flighttime']);
    $pirep['aircraftid'] = intval($pirep['aircraftid']);
    $pirep['status'] = intval($pirep['status']);

    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => $pirep,
    ]);
});

// Edit PIREP
Router::add('/pireps/([0-9]*)', function($pirepId) {
    global $_apiUser, $_authType;
    if ($_authType == AuthType::ApiKey) {
        accessDenied();
    }
    
    $pirep = [];

    $pirep = (array)$pirep;
    if (!empty(Input::get('flightnum'))) $pirep["flightnum"] = Input::get('flightnum');
    if (!empty(Input::get('departure'))) $pirep["departure"] = Input::get('departure');
    if (!empty(Input::get('arrival'))) $pirep["arrival"] = Input::get('arrival');
    if (!empty(Input::get('date'))) $pirep["date"] = Input::get('date');

    $res = Pirep::update($pirepId, $pirep) ? ErrorCode::NoError : ErrorCode::InternalServerError;
    echo Json::encode([
        "status" => $res,
        "result" => null,
    ]);
}, 'put');

// File PIREP
Router::add('/pireps', function() {     
    global $_authType, $user;
    if ($_authType == AuthType::ApiKey) {
        accessDenied();
    }

    $multi = "None";
    $finalFTime = Time::strToSecs(Input::get('flighttime'));

    if (!empty(Input::get('multi'))) {
        $multiplier = Pirep::findMultiplier(Input::get('multi'));
        if (!$multiplier) {
            badReq(ErrorCode::MultiplierNotFound);
        }

        $multi = $multiplier->name;
        $finalFTime *= $multiplier->multiplier;
    }

    $allowedaircraft = $user->getAvailableAircraft();
    $allowed = false;
    foreach ($allowedaircraft as $a) {
        if ($a["id"] == Input::get('aircraft')) {
            $allowed = true;
        }
    }

    if (!$allowed) {
        badReq(ErrorCode::RankNotSufficent);
    }

    $response = VANet::sendPirep(array (
        'AircraftID' => Aircraft::idToLiveryId(Input::get('aircraft')),
        'Arrival' => Input::get('arr'),
        'DateTime' => Input::get('date'),
        'Departure' => Input::get('dep'),
        'FlightTime' => Time::strToSecs(Input::get('ftime')),
        'FuelUsed' => Input::get('fuel'),
        'PilotId' => $user->data()->ifuserid
    ));

    $response = Json::decode($response->body);
    if ($response['success'] != true) {
        internalError();
    }

    if (!Pirep::file(array(
        'flightnum' => Input::get('flightnum'),
        'departure' => Input::get('departure'),
        'arrival' => Input::get('arrival'),
        'flighttime' => $finalFTime,
        'pilotid' => $user->data()->id,
        'date' => Input::get('date'),
        'aircraftid' => Input::get('aircraft'),
        'multi' => $multi
    ))) {
        internalError();
    } else {
        echo Json::encode([
            "status" => ErrorCode::NoError,
            "result" => null,
        ]);
    }
}, 'post');

// Accept PIREP
Router::add('/pireps/accept/([0-9]+)', function($pirepId) {
    global $_authType, $user;
    if ($_authType == AuthType::ApiKey) {
        accessDenied();
    }

    if (!$user->hasPermission('pirepmanage') || !$user->hasPermission('admin')) {
        accessDenied();
    }

    Pirep::accept($pirepId);
    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => null,
    ]);
});

// Deny PIREP
Router::add('/pireps/deny/([0-9]+)', function($pirepId) {
    global $_authType, $user;
    if ($_authType == AuthType::ApiKey) {
        accessDenied();
    }

    if (!$user->hasPermission('pirepmanage') || !$user->hasPermission('admin')) {
        accessDenied();
    }

    Pirep::decline($pirepId);
    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => null,
    ]);
});

// View User Info
Router::add('/about', function() {
    global $_apiUser, $_authType;
    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => [
            "id" => intval($_apiUser->id),
            "callsign" => $_apiUser->callsign,
            "name" => $_apiUser->name,
            "email" => $_authType != AuthType::ApiKey ? $_apiUser->email : null,
            "ifc" => $_apiUser->ifc,
            "transfer_hours" => intval($_apiUser->transhours),
            "transfer_flights" => intval($_apiUser->transflights),
            "violation_landing" => $_apiUser->violand,
            "grade" => $_apiUser->grade,
            "joined" => date_format(date_create($_apiUser->joined), 'Y-m-d'),
        ],
    ]);
});

// Update User Info
Router::add('/about', function() {
    global $_authType, $user, $_apiUser;
    if ($_authType == AuthType::ApiKey) {
        accessDenied();
    }

    if (!empty(Input::get('callsign'))) {
        $csPattern = Config::get('VA_CALLSIGN_FORMAT');
        if (!Regex::match($csPattern, Input::get('callsign'))) {
            badReq(ErrorCode::CallsignNotValid);
        }

        if (!Callsign::assigned(Input::get('callsign'), $_apiUser->id)) {
            badReq(ErrorCode::CallsignTaken);
        }
    }

    $updUser = [];
    if (!empty(Input::get('callsign'))) $updUser['callsign'] = Input::get('callsign');
    if (!empty(Input::get('name'))) $updUser['name'] = Input::get('name');
    if (!empty(Input::get('email'))) $updUser['email'] = Input::get('email');
    if (!empty(Input::get('ifc'))) $updUser['ifc'] = Input::get('ifc');
    
    try {
        $user->update($updUser);
        echo Json::encode([
            "status" => ErrorCode::NoError,
            "result" => null,
        ]);
    } catch (Exception $e) {
        internalError();
    }
}, 'put');

// View All Events
Router::add('/events', function() {
    if (!VANet::isGold()) badReq(ErrorCode::VaNotGold);

    $events = array_filter(VANet::getEvents(), function($e) {
        return $e["visible"];
    });
    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => $events,
    ]);
});

// View Specific Event
Router::add('/events/([0-9a-zA-z]{8}-[0-9a-zA-z]{4}-[0-9a-zA-z]{4}-[0-9a-zA-z]{4}-[0-9a-zA-z]{12})', function($eventId) {
    if (!VANet::isGold()) badReq(ErrorCode::VaNotGold);
    
    $event = VANet::findEvent($eventId);
    if ($event === FALSE) notFound();

    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => $event,
    ]);
});

// View All News
Router::add('/news', function() {
    $news = News::get();

    $i = 0;
    foreach ($news as $n) {
        foreach ($n as $key => $val) {
            if (is_numeric($val)) $news[$i][$key] = intval($val);
            if ($key == 'dateposted') $news[$i][$key] = date_format(date_create($val), 'Y-m-d');
        }
        $i++;
    }
    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => $news,
    ]);
});

// View Specific News Item
Router::add('/news/([0-9]+)', function($newsId) {
    $article = News::find($newsId);
    if ($article === FALSE) notFound();

    $article->dateposted = date_format(date_create($article->dateposted), "Y-m-d");
    foreach ($article as $key => $val) {
        if (is_numeric($val)) $article->$key = intval($val);
        if ($key == 'dateposted') $article->$key = date_format(date_create($val), "Y-m-d");
    }

    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => $article,
    ]);
});

// Add News Item
Router::add('/news', function() {
    global $_authType, $user;
    if ($_authType == AuthType::ApiKey) {
        accessDenied();
    }
    if (!$user->hasPermission('newsmanage') || !$user->hasPermission('admin')) {
        accessDenied();
    }

    News::add([
        "subject" => Input::get('subject'),
        "content" => Input::get('content'),
        "author" => $user->data()->name,
    ]);
    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => null,
    ]);
}, 'post');

// Edit News Item
Router::add('/news/([0-9]+)', function($newsId) {
    global $_authType, $user;
    if ($_authType == AuthType::ApiKey) {
        accessDenied();
    }
    if (!$user->hasPermission('newsmanage') || !$user->hasPermission('admin')) {
        accessDenied();
    }

    $updNews = [];
    if (!empty(Input::get('subject'))) $updNews["subject"] = Input::get('subject');
    if (!empty(Input::get('content'))) $updNews["content"] = Input::get('content');

    News::edit($newsId, $updNews);
    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => null,
    ]);
}, 'put');

// Delete News Item
Router::add('/news/([0-9]+)', function($newsId) {
    global $_authType, $user;
    if ($_authType == AuthType::ApiKey) {
        accessDenied();
    }
    if (!$user->hasPermission('newsmanage') || !$user->hasPermission('admin')) {
        accessDenied();
    }

    News::archive($newsId);
    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => null,
    ]);
}, 'delete');

// View All Routes
Router::add('/routes', function() {
    // TODO: Fix This
    $routes = array_map(function($r) {
        $r['aircraft'] = array_map(function($a) {
            unset($a['liveryid']);
            return $a;
        }, $r['aircraft']);
        foreach ($r as $key => $val) {
            if (is_numeric($val) && $key != 'fltnum') $r[$key] = intval($val);
        }

        return $r;
    }, Route::fetchAll());
    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => $routes,
    ]);
});

// View Specific Route
Router::add('/routes/([0-9]+)', function($routeId) {
    // TODO: Account for Multiple Aircraft
    $route = Route::find($routeId);
    if ($route->count() == 0) notFound();

    $selectedRoute = $route->first();
    $selectedRoute->duration = intval($selectedRoute->duration);
    $selectedRoute->id = intval($selectedRoute->id);
    unset($selectedRoute->aircraftid);

    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => $selectedRoute,
    ]);
});

// Add Route
Router::add('/routes', function() {
    global $_authType, $user;
    if ($_authType == AuthType::ApiKey) {
        accessDenied();
    }
    if (!$user->hasPermission('opsmanage') || !$user->hasPermission('admin')) {
        accessDenied();
    }

    // TODO: Make This Assoc and Account for Multiple Aircraft
    Route::add([
        Input::get('fltnum'),
        Input::get('dep'),
        Input::get('arr'),
        Input::get('duration'),
        Input::get('aircraftid'),
    ]);
    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => null,
    ]);
}, 'post');

// Edit Route
Router::add('/routes/([0-9]+)', function($routeId) {
    // TODO: Account for Multiple Aircraft
    global $_authType, $user;
    if ($_authType == AuthType::ApiKey) {
        accessDenied();
    }
    if (!$user->hasPermission('opsmanage') || !$user->hasPermission('admin')) {
        accessDenied();
    }

    $updRoute = [];
    if (!empty(Input::get('fltnum'))) $updRoute['fltnum'] = Input::get('fltnum');
    if (!empty(Input::get('arr'))) $updRoute['arr'] = Input::get('arr');
    if (!empty(Input::get('dep'))) $updRoute['dep'] = Input::get('dep');
    if (!empty(Input::get('duration'))) $updRoute['duration'] = Input::get('duration');
    if (!empty(Input::get('aircraftid'))) $updRoute['aircraftid'] = Input::get('aircraftid');

    Route::update($routeId, $updRoute);
    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => null,
    ]);
}, 'put');

// Delete Route
Router::add('/routes/([0-9]+)', function($routeId) {
    global $_authType, $user;
    if ($_authType == AuthType::ApiKey) {
        accessDenied();
    }
    if (!$user->hasPermission('opsmanage') || !$user->hasPermission('admin')) {
        accessDenied();
    }

    Route::delete($routeId);
    Route::removeAircraft($routeId);
    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => null,
    ]);
}, 'delete');

Router::add('/aircraft', function() {
    $allaircraft = array_map(function($a) {
        unset($a->status);
        foreach ($a as $key => $val) {
            if (is_numeric($val)) {
                $a->$key = intval($val);
            }
        }

        return $a;
    }, Aircraft::fetchActiveAircraft()->results());

    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => $allaircraft,
    ]);
});

Router::add('/aircraft/([0-9]+)', function($aircraftId) {
    $a = Aircraft::fetch($aircraftId);
    if ($a === FALSE) {
        notFound();
    }

    unset($a->status);
    foreach ($a as $key => $val) {
        if (is_numeric($val)) {
            $a->$key = intval($val);
        }
    }
    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => $a,
    ]);
});

Router::add('/notifications', function() {
    global $_apiUser;
    $notifications = array_map(function($n) {
        $n->datetime = $n->formattedDate;
        unset($n->formattedDate);
        unset($n->pilotid);
        foreach ($n as $key => $val) {
            if (is_numeric($val)) {
                $n->$key = intval($val);
            }
        }

        return $n;
    }, Notifications::mine($_apiUser->id));
    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => $notifications,
    ]);
});

Router::add('/ranks', function() {
    $ranks = array_map(function($r) {
        foreach ($r as $key => $val) {
            if (is_numeric($val)) {
                $r->$key = intval($val);
            }
        }

        return $r;
    }, Rank::fetchAllNames()->results());

    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => $ranks,
    ]);
});

Router::add('/ranks/([0-9]+)', function($rankId) {
    $r = Rank::find($rankId);
    if ($r === FALSE) {
        notFound();
    }

    foreach ($r as $key => $val) {
        if (is_numeric($val)) {
            $r->$key = intval($val);
        }
    }

    echo Json::encode([
        "status" => ErrorCode::NoError,
        "result" => $r,
    ]);
});

Router::run('/api.php');