<?php
class VKParser {
    private $access_token;
    private $v = '5.131';

    public function __construct($access_token) {
        $this->access_token = $access_token;
    }


    private function Getparams($type_req, $id_param = 0, $full_name = '', $day = 0, $month = 0, $year = 0)
    {
        // Базовые параметры для всех запросов
        $base_params = [
            'access_token' => $this->access_token,
            'v' => $this->v,
            'fields' => implode(',', [
                'photo_max_orig',
                'status',
                'bdate',
                'relation',
                'city',
                'career,education',
                'site',
                'personal,contacts',
                'interests,music,movies,tv,books,games',
                'can_access_closed', // Добавляем проверку приватности
                'is_closed'          // Добавляем проверку приватности
            ])
        ];

        if ($type_req == 1) {
            return array_merge($base_params, [
                'phone' =>$id_param,
                'count' => 1
            ]);
        }
        elseif ($type_req == 2) {
            $params = [
                'q' => trim($full_name),
                'count' => 1,
                'sort' => 1
            ];
            if ($day > 0 && $month > 0 && $year > 0) {
                $params['birth_day'] = (int)$day;
                $params['birth_month'] = (int)$month;
                $params['birth_year'] = (int)$year;
            }

            return array_merge($base_params, $params);
        }
        elseif ($type_req == 3) {
            return array_merge($base_params, [
                'user_ids' => $id_param
            ]);
        }

        throw new InvalidArgumentException("Неверный тип запроса");
    }
    public function getUserInfo(int $typeReq, $userId = 0, string $fullName = '', int $day = 0, int $month = 0, int $year = 0): ?array
    {
        try {
            $params = $this->getParams($typeReq, $userId, $fullName, $day, $month, $year);
            $method = ($typeReq != 3) ? 'users.search' : 'users.get';

            $url = "https://api.vk.com/method/{$method}?" . http_build_query($params);
            $response = file_get_contents($url);
            if ($response === false) {
                throw new RuntimeException("Ошибка при запросе к API VK");
            }
            $data = json_decode($response, true);
            if (isset($data['error'])) {
                throw new RuntimeException("VK API Error: {$data['error']['error_msg']}");
            }

            if (empty($data['response']['items'][0]) and empty($data['response'][0])) {
                return null;
            }
            $user = ($typeReq != 3 ) ? $data['response']['items'][0] : $data['response'][0];
            return $this->formatUserData($user);

        } catch (Exception $e) {
            error_log("Error: " . $e->getMessage());
            return null;
        }
    }

    private function formatUserData(array $user): array
    {
        $result = [
            'ФИО' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'Аватар' => $user['photo_max_orig'] ?? null,
            'Статус' => $user['status'] ?? null,
            'ДР' => $user['bdate'] ?? null,
            'Семейное положение' => $this->getRelation($user['relation'] ?? 0),
            'Город' => $user['city']['title'] ?? null,
            'Место учебы/работы' => $this->getWorkEducationInfo($user),
            'Сайт' => $user['site'] ?? null,
            'Личная информация' => $this->getPersonalInfo($user),
            'Контактная информация' => $this->getContactInfo($user),
            'Интересы' => $this->getInterestsInfo($user)
        ];

        if (!empty($user['id'])) {
            $result['Друзья'] = $this->getFriends($user['id']);
            $result['Подписчики'] = $this->getFollowers($user['id']);
            $result['Подписки'] = $this->getSubscriptions($user['id']);
            $result['Сообщества'] = $this->getGroups($user['id']);
        }

        return $result;
    }

    private function getRelation($code) {
        $relations = [
            1 => 'не женат/не замужем',
            2 => 'есть друг/есть подруга',
            3 => 'помолвлен/помолвлена',
            4 => 'женат/замужем',
            5 => 'всё сложно',
            6 => 'в активном поиске',
            7 => 'влюблён/влюблена',
            8 => 'в гражданском браке',
            0 => 'не указано'
        ];
        return $relations[$code] ?? 'не указано';
    }

    private function getWorkEducationInfo($user) {
        $result = [];

        // Место работы
        if (!empty($user['career'])) {
            foreach ($user['career'] as $job) {
                $work = [];
                if (!empty($job['company'])) $work['Компания'] = $job['company'];
                if (!empty($job['position'])) $work['Должность'] = $job['position'];
                if (!empty($job['city_id'])) $work['Город'] = $job['city_id'];
                if (!empty($job['from'])) $work['С'] = $job['from'];
                if (!empty($job['until'])) $work['До'] = $job['until'];
                $result[] = $work;
            }
        }
        $edu = [];
        if (!empty($user['university_name']))
            $edu['Университет'] = $user['university_name'];
        if (!empty($user['faculty_name']))
            $edu['Факультет'] = $user['faculty_name'];
        if (!empty($user['graduation']))
            $edu['Год окончания'] = $user['graduation'];
        if (!empty($edu))
            $result[] = $edu;
        return $result;
    }

    private function getPersonalInfo($user) {
        if (empty($user['personal']))
            return null;
        $personal = $user['personal'];
        $result = [];
        if (!empty($personal['langs']))
            $result['Языки'] = implode(', ', $personal['langs']);
        if (!empty($personal['religion']))
            $result['Религия'] = $personal['religion'];
        if (!empty($personal['inspired_by']))
            $result['Вдохновение'] = $personal['inspired_by'];
        if (!empty($personal['people_main']))
            $result['Главное в людях'] = $personal['people_main'];
        if (!empty($personal['life_main']))
            $result['Главное в жизни'] = $personal['life_main'];
        if (!empty($personal['smoking']))
            $result['Курение'] = $personal['smoking'];
        if (!empty($personal['alcohol']))
            $result['Алкоголь'] = $personal['alcohol'];
        return $result;
    }

    private function getContactInfo($user) {
        if (empty($user['contacts'])) return null;

        $contacts = $user['contacts'];
        $result = [];

        if (!empty($contacts['mobile_phone'])) $result['Мобильный телефон'] = $contacts['mobile_phone'];
        if (!empty($contacts['home_phone'])) $result['Домашний телефон'] = $contacts['home_phone'];
        if (!empty($contacts['skype'])) $result['Skype'] = $contacts['skype'];
        if (!empty($contacts['instagram'])) $result['Instagram'] = $contacts['instagram'];
        if (!empty($contacts['facebook'])) $result['Facebook'] = 'https://facebook.com/' . $contacts['facebook'];
        if (!empty($contacts['twitter'])) $result['Twitter'] = 'https://twitter.com/' . $contacts['twitter'];

        return $result;
    }

    private function getInterestsInfo($user) {
        $result = [];

        if (!empty($user['interests'])) $result['Интересы'] = $user['interests'];
        if (!empty($user['music'])) $result['Музыка'] = $user['music'];
        if (!empty($user['movies'])) $result['Фильмы'] = $user['movies'];
        if (!empty($user['tv'])) $result['ТВ'] = $user['tv'];
        if (!empty($user['books'])) $result['Книги'] = $user['books'];
        if (!empty($user['games'])) $result['Игры'] = $user['games'];

        return $result;
    }

    private function getFriends($user_id) {
        $params = [
            'user_id' => $user_id,
            'count' => 5000,
            'fields' => 'domain',
            'access_token' => $this->access_token,
            'v' => $this->v
        ];

        $url = 'https://api.vk.com/method/friends.get?' . http_build_query($params);
        $response = file_get_contents($url);
        $data = json_decode($response, true);

        if (empty($data['response']['items'])) {
            return [
                'Количество' => 0,
                'Ссылки' => []
            ];
        }

        $links = [];
        foreach ($data['response']['items'] as $friend) {
            $domain = $friend['domain'] ?? 'id' . $friend['id'];
            $links[] = 'https://vk.com/' . $domain;
        }

        return [
            'Количество' => $data['response']['count'] ?? count($links),
            'Ссылки' => $links
        ];
    }

    private function getFollowers($user_id) {
        $params = [
            'user_id' => $user_id,
            'count' => 1000,
            'fields' => 'domain',
            'access_token' => $this->access_token,
            'v' => $this->v
        ];

        $url = 'https://api.vk.com/method/users.getFollowers?' . http_build_query($params);
        $response = file_get_contents($url);
        $data = json_decode($response, true);

        if (empty($data['response']['items'])) {
            return [
                'Количество' => 0,
                'Ссылки' => []
            ];
        }

        $links = [];
        foreach ($data['response']['items'] as $follower) {
            $domain = $follower['domain'] ?? 'id' . $follower['id'];
            $links[] = 'https://vk.com/' . $domain;
        }

        return [
            'Количество' => $data['response']['count'] ?? count($links),
            'Ссылки' => $links
        ];
    }

    private function getSubscriptions($user_id) {
        $params = [
            'user_id' => $user_id,
            'extended' => 1,
            'count' => 200,
            'fields' => 'screen_name',
            'access_token' => $this->access_token,
            'v' => $this->v
        ];

        $url = 'https://api.vk.com/method/users.getSubscriptions?' . http_build_query($params);
        $response = file_get_contents($url);
        $data = json_decode($response, true);

        if (empty($data['response']['items'])) {
            return [
                'Количество' => 0,
                'Ссылки' => []
            ];
        }

        $links = [];
        foreach ($data['response']['items'] as $item) {
            if (isset($item['screen_name'])) {
                $links[] = 'https://vk.com/' . $item['screen_name'];
            } elseif (isset($item['id'])) {
                $links[] = 'https://vk.com/public' . $item['id'];
            }
        }

        return [
            'Количество' => $data['response']['count'] ?? count($links),
            'Ссылки' => $links
        ];
    }

    private function getGroups($user_id) {
        $params = [
            'user_id' => $user_id,
            'extended' => 1,
            'filter' => 'admin,editor,moder',
            'count' => 1000,
            'access_token' => $this->access_token,
            'v' => $this->v
        ];

        $url = 'https://api.vk.com/method/groups.get?' . http_build_query($params);
        $response = file_get_contents($url);
        $data = json_decode($response, true);

        if (empty($data['response']['items'])) {
            return [
                'Количество' => 0,
                'Ссылки' => []
            ];
        }

        $links = [];
        foreach ($data['response']['items'] as $group) {
            if (isset($group['screen_name'])) {
                $links[] = [
                    'Ссылка' => 'https://vk.com/' . $group['screen_name'],
                    'Роль' => $this->getGroupRole($group)
                ];
            } elseif (isset($group['id'])) {
                $links[] = [
                    'Ссылка' => 'https://vk.com/public' . $group['id'],
                    'Роль' => $this->getGroupRole($group)
                ];
            }
        }

        return [
            'Количество' => $data['response']['count'] ?? count($links),
            'Ссылки' => $links
        ];
    }

    private function getGroupRole($group) {
        if ($group['is_admin']) return 'Администратор';
        if ($group['is_moder']) return 'Модератор';
        if ($group['is_editor']) return 'Редактор';
        return 'Участник';
    }
}

// Как использовать ?
$access_token = ''; // Здесь надо поменять на ваш Access token :)
$parser = new VKParser($access_token);
$userInfo = $parser->getUserInfo(2, 0, 'Ровда Владимир', 1,10,2005);
;echo '<pre>';
print_r($userInfo);
echo '</pre>';
?>
