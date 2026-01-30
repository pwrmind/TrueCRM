<?php
/**
 * CRM SYSTEM v1.0 - Полнофункциональная CRM с веб-интерфейсом
 * Архитектура: Clean Architecture + DDD + Hexagonal
 * Веб-интерфейс: Tailwind CSS
 * PHP 8.1+
 */

declare(strict_types=1);

// ==================== КОНСТАНТЫ И НАСТРОЙКИ ====================
const APP_VERSION = '1.0.0';
const APP_NAME = 'CRM Pro';
const SESSION_KEY = 'crm_auth';

// Режим отладки
define('CRM_DEBUG', true);

// Начало сессии
session_start();

// ==================== VALUE OBJECTS ====================

final class Email implements \Stringable
{
    private string $value;
    
    public function __construct(string $email)
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Некорректный email: {$email}");
        }
        $this->value = strtolower(trim($email));
    }
    
    public function getValue(): string { return $this->value; }
    public function __toString(): string { return $this->value; }
}

final class Phone implements \Stringable
{
    private string $value;
    
    public function __construct(string $phone)
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($cleaned) < 10) {
            throw new \InvalidArgumentException("Некорректный номер телефона");
        }
        $this->value = $cleaned;
    }
    
    public function getFormatted(): string
    {
        return '+7 (' . substr($this->value, 0, 3) . ') ' . substr($this->value, 3, 3) . '-' . substr($this->value, 6, 2) . '-' . substr($this->value, 8, 2);
    }
    
    public function __toString(): string { return $this->getFormatted(); }
}

final class Money
{
    private int $amount;
    private string $currency;
    
    public function __construct(int $amount, string $currency = 'RUB')
    {
        $this->amount = $amount;
        $this->currency = strtoupper($currency);
    }
    
    public static function fromFloat(float $amount, string $currency = 'RUB'): self
    {
        return new self((int)($amount * 100), $currency);
    }
    
    public function getFloatAmount(): float { return $this->amount / 100; }
    public function getCurrency(): string { return $this->currency; }
    
    public function __toString(): string
    {
        return number_format($this->getFloatAmount(), 2, '.', ' ') . ' ' . $this->currency;
    }
}

final class LeadStatus
{
    private string $value;
    
    private const STATUSES = [
        'new' => ['name' => 'Новый', 'color' => 'blue'],
        'in_progress' => ['name' => 'В работе', 'color' => 'yellow'],
        'qualified' => ['name' => 'Квалифицирован', 'color' => 'purple'],
        'converted' => ['name' => 'Конвертирован', 'color' => 'green'],
        'disqualified' => ['name' => 'Отказ', 'color' => 'red'],
    ];
    
    public function __construct(string $status)
    {
        if (!isset(self::STATUSES[$status])) {
            throw new \InvalidArgumentException("Недопустимый статус лида: {$status}");
        }
        $this->value = $status;
    }
    
    public function getValue(): string { return $this->value; }
    public function getName(): string { return self::STATUSES[$this->value]['name']; }
    public function getColor(): string { return self::STATUSES[$this->value]['color']; }
    public function __toString(): string { return $this->value; }
}

final class LeadSource
{
    private string $source;
    
    private const SOURCES = [
        'website' => ['name' => 'Сайт', 'icon' => 'globe'],
        'phone' => ['name' => 'Телефон', 'icon' => 'phone'],
        'email' => ['name' => 'Email', 'icon' => 'envelope'],
        'referral' => ['name' => 'Рекомендация', 'icon' => 'user-group'],
        'social' => ['name' => 'Соцсети', 'icon' => 'chat'],
    ];
    
    public function __construct(string $source)
    {
        $this->source = $source;
    }
    
    public function getName(): string { return self::SOURCES[$this->source]['name'] ?? $this->source; }
    public function getIcon(): string { return self::SOURCES[$this->source]['icon'] ?? 'question-mark'; }
    public function __toString(): string { return $this->source; }
}

// ==================== СУЩНОСТИ ====================

class User
{
    private string $id;
    private Email $email;
    private string $firstName;
    private string $lastName;
    private string $passwordHash;
    private string $role;
    private \DateTimeImmutable $createdAt;
    
    public function __construct(
        string $id,
        Email $email,
        string $firstName,
        string $lastName,
        string $passwordHash,
        string $role = 'user'
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->passwordHash = $passwordHash;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
    }
    
    public function getId(): string { return $this->id; }
    public function getEmail(): Email { return $this->email; }
    public function getFullName(): string { return $this->firstName . ' ' . $this->lastName; }
    public function getFirstName(): string { return $this->firstName; }
    public function getLastName(): string { return $this->lastName; }
    public function getRole(): string { return $this->role; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }
    
    public function isAdmin(): bool { return $this->role === 'admin'; }
    public function isManager(): bool { return $this->role === 'manager'; }
    public function isUser(): bool { return $this->role === 'user'; }
}

class Lead
{
    private string $id;
    private string $title;
    private string $contactName;
    private Email $contactEmail;
    private ?Phone $contactPhone;
    private LeadStatus $status;
    private LeadSource $source;
    private ?string $company;
    private ?Money $estimatedValue;
    private ?string $assignedTo;
    private array $notes;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    
    public function __construct(
        string $id,
        string $title,
        string $contactName,
        Email $contactEmail,
        LeadSource $source,
        ?Phone $contactPhone = null,
        ?string $company = null
    ) {
        $this->id = $id;
        $this->title = $title;
        $this->contactName = $contactName;
        $this->contactEmail = $contactEmail;
        $this->contactPhone = $contactPhone;
        $this->company = $company;
        $this->source = $source;
        $this->status = new LeadStatus('new');
        $this->estimatedValue = null;
        $this->assignedTo = null;
        $this->notes = [];
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    public function getId(): string { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getContactName(): string { return $this->contactName; }
    public function getContactEmail(): Email { return $this->contactEmail; }
    public function getContactPhone(): ?Phone { return $this->contactPhone; }
    public function getStatus(): LeadStatus { return $this->status; }
    public function getSource(): LeadSource { return $this->source; }
    public function getCompany(): ?string { return $this->company; }
    public function getEstimatedValue(): ?Money { return $this->estimatedValue; }
    public function getAssignedTo(): ?string { return $this->assignedTo; }
    public function getNotes(): array { return $this->notes; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    
    public function changeStatus(LeadStatus $newStatus): void
    {
        $this->status = $newStatus;
        $this->updatedAt = new \DateTimeImmutable();
        $this->addNote("Статус изменен на: " . $newStatus->getName());
    }
    
    public function assignTo(string $userId): void
    {
        $this->assignedTo = $userId;
        $this->updatedAt = new \DateTimeImmutable();
        $this->addNote("Лид назначен");
    }
    
    public function setEstimatedValue(?Money $value): void
    {
        $this->estimatedValue = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    public function addNote(string $note): void
    {
        $this->notes[] = [
            'text' => $note,
            'timestamp' => new \DateTimeImmutable(),
        ];
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    public function update(array $data): void
    {
        if (isset($data['title'])) $this->title = $data['title'];
        if (isset($data['contact_name'])) $this->contactName = $data['contact_name'];
        if (isset($data['company'])) $this->company = $data['company'];
        $this->updatedAt = new \DateTimeImmutable();
        $this->addNote("Данные обновлены");
    }
}

class Deal
{
    private string $id;
    private string $title;
    private Money $amount;
    private string $stage;
    private string $leadId;
    private string $ownerId;
    private int $probability;
    private ?\DateTimeInterface $closeDate;
    private bool $isClosed;
    private bool $isWon;
    private ?string $closeReason;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    
    private const STAGES = [
        'prospecting' => ['name' => 'Знакомство', 'color' => 'gray'],
        'qualification' => ['name' => 'Квалификация', 'color' => 'blue'],
        'proposal' => ['name' => 'Предложение', 'color' => 'yellow'],
        'negotiation' => ['name' => 'Переговоры', 'color' => 'purple'],
        'closed_won' => ['name' => 'Успех', 'color' => 'green'],
        'closed_lost' => ['name' => 'Потеря', 'color' => 'red'],
    ];
    
    public function __construct(
        string $id,
        string $title,
        Money $amount,
        string $ownerId,
        string $leadId
    ) {
        $this->id = $id;
        $this->title = $title;
        $this->amount = $amount;
        $this->ownerId = $ownerId;
        $this->leadId = $leadId;
        $this->stage = 'prospecting';
        $this->probability = 10;
        $this->closeDate = null;
        $this->isClosed = false;
        $this->isWon = false;
        $this->closeReason = null;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    public function getId(): string { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getAmount(): Money { return $this->amount; }
    public function getStage(): string { return $this->stage; }
    public function getStageName(): string { return self::STAGES[$this->stage]['name'] ?? $this->stage; }
    public function getStageColor(): string { return self::STAGES[$this->stage]['color'] ?? 'gray'; }
    public function getLeadId(): string { return $this->leadId; }
    public function getOwnerId(): string { return $this->ownerId; }
    public function getProbability(): int { return $this->probability; }
    public function getCloseDate(): ?\DateTimeInterface { return $this->closeDate; }
    public function isClosed(): bool { return $this->isClosed; }
    public function isWon(): bool { return $this->isWon; }
    public function getCloseReason(): ?string { return $this->closeReason; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    
    public function updateStage(string $stage): void
    {
        $this->stage = $stage;
        $this->updatedAt = new \DateTimeImmutable();
        
        // Автоматическая установка вероятности
        $probabilities = [
            'prospecting' => 10,
            'qualification' => 25,
            'proposal' => 50,
            'negotiation' => 75,
            'closed_won' => 100,
            'closed_lost' => 0,
        ];
        
        if (isset($probabilities[$stage])) {
            $this->probability = $probabilities[$stage];
        }
    }
    
    public function close(bool $won, ?string $reason = null): void
    {
        $this->isClosed = true;
        $this->isWon = $won;
        $this->closeReason = $reason;
        $this->closeDate = new \DateTimeImmutable();
        $this->stage = $won ? 'closed_won' : 'closed_lost';
        $this->probability = $won ? 100 : 0;
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    public function updateAmount(Money $amount): void
    {
        $this->amount = $amount;
        $this->updatedAt = new \DateTimeImmutable();
    }
}

// ==================== РЕПОЗИТОРИИ ====================

class UserRepository
{
    private array $users = [];
    
    public function __construct()
    {
        // Создание тестового администратора
        $admin = new User(
            'user_001',
            new Email('admin@crm.local'),
            'Администратор',
            'Системы',
            password_hash('admin123', PASSWORD_DEFAULT),
            'admin'
        );
        $this->save($admin);
        
        // Создание тестового менеджера
        $manager = new User(
            'user_002',
            new Email('manager@crm.local'),
            'Иван',
            'Менеджеров',
            password_hash('manager123', PASSWORD_DEFAULT),
            'manager'
        );
        $this->save($manager);
    }
    
    public function findById(string $id): ?User
    {
        return $this->users[$id] ?? null;
    }
    
    public function findByEmail(string $email): ?User
    {
        foreach ($this->users as $user) {
            if ($user->getEmail()->getValue() === $email) {
                return $user;
            }
        }
        return null;
    }
    
    public function save(User $user): void
    {
        $this->users[$user->getId()] = $user;
    }
    
    public function findAll(): array
    {
        return array_values($this->users);
    }
    
    public function count(): int
    {
        return count($this->users);
    }
}

class LeadRepository
{
    private array $leads = [];
    
    public function __construct()
    {
        $this->createSampleLeads();
    }
    
    private function createSampleLeads(): void
    {
        $leads = [
            [
                'id' => 'lead_001',
                'title' => 'Запрос на интеграцию CRM',
                'contact_name' => 'Анна Петрова',
                'contact_email' => 'anna@client.ru',
                'contact_phone' => '79161234567',
                'company' => 'ООО "Клиент"',
                'source' => 'website',
                'estimated_value' => 150000,
                'status' => 'new',
                'notes' => 'Клиент заинтересован в интеграции с 1С',
                'assigned_to' => 'user_002'
            ],
            [
                'id' => 'lead_002',
                'title' => 'Консультация по настройке',
                'contact_name' => 'Сергей Иванов',
                'contact_email' => 'sergey@company.com',
                'contact_phone' => '79031234567',
                'company' => 'ИП Иванов',
                'source' => 'email',
                'estimated_value' => 50000,
                'status' => 'in_progress',
                'notes' => 'Требуется консультация по миграции данных',
                'assigned_to' => null
            ],
            [
                'id' => 'lead_003',
                'title' => 'Разработка кастомного модуля',
                'contact_name' => 'Мария Сидорова',
                'contact_email' => 'maria@techcorp.ru',
                'company' => 'ТехноКорп',
                'source' => 'phone',
                'estimated_value' => 300000,
                'status' => 'qualified',
                'notes' => 'Требуется разработка модуля аналитики',
                'assigned_to' => 'user_002'
            ],
        ];
        
        foreach ($leads as $data) {
            $lead = new Lead(
                $data['id'],
                $data['title'],
                $data['contact_name'],
                new Email($data['contact_email']),
                new LeadSource($data['source']),
                isset($data['contact_phone']) ? new Phone($data['contact_phone']) : null,
                $data['company'] ?? null
            );
            
            if (isset($data['estimated_value'])) {
                $lead->setEstimatedValue(Money::fromFloat($data['estimated_value']));
            }
            
            if (isset($data['status'])) {
                $lead->changeStatus(new LeadStatus($data['status']));
            }
            
            if (isset($data['notes'])) {
                $lead->addNote($data['notes']);
            }
            
            if (isset($data['assigned_to']) && $data['assigned_to']) {
                $lead->assignTo($data['assigned_to']);
            }
            
            $this->save($lead);
        }
    }
    
    public function findById(string $id): ?Lead
    {
        return $this->leads[$id] ?? null;
    }
    
    public function findAll(): array
    {
        return array_values($this->leads);
    }
    
    public function findByStatus(string $status): array
    {
        return array_filter($this->leads, fn($lead) => $lead->getStatus()->getValue() === $status);
    }
    
    public function findByAssignedTo(string $userId): array
    {
        return array_filter($this->leads, fn($lead) => $lead->getAssignedTo() === $userId);
    }
    
    public function save(Lead $lead): void
    {
        $this->leads[$lead->getId()] = $lead;
    }
    
    public function delete(string $id): bool
    {
        if (isset($this->leads[$id])) {
            unset($this->leads[$id]);
            return true;
        }
        return false;
    }
    
    public function count(): int
    {
        return count($this->leads);
    }
    
    public function countByStatus(string $status): int
    {
        return count($this->findByStatus($status));
    }
}

class DealRepository
{
    private array $deals = [];
    
    public function __construct()
    {
        $this->createSampleDeals();
    }
    
    private function createSampleDeals(): void
    {
        // Создаем тестовую сделку
        $deal = new Deal(
            'deal_001',
            'Интеграция CRM для ООО "Клиент"',
            Money::fromFloat(150000),
            'user_002',
            'lead_001'
        );
        $deal->updateStage('proposal');
        $deal->updateAmount(Money::fromFloat(180000));
        $this->save($deal);
        
        // Еще одна сделка
        $deal2 = new Deal(
            'deal_002',
            'Миграция данных для ИП Иванов',
            Money::fromFloat(50000),
            'user_002',
            'lead_002'
        );
        $deal2->updateStage('qualification');
        $this->save($deal2);
    }
    
    public function findById(string $id): ?Deal
    {
        return $this->deals[$id] ?? null;
    }
    
    public function findAll(): array
    {
        return array_values($this->deals);
    }
    
    public function findByOwner(string $userId): array
    {
        return array_filter($this->deals, fn($deal) => $deal->getOwnerId() === $userId);
    }
    
    public function findByStage(string $stage): array
    {
        return array_filter($this->deals, fn($deal) => $deal->getStage() === $stage);
    }
    
    public function save(Deal $deal): void
    {
        $this->deals[$deal->getId()] = $deal;
    }
    
    public function count(): int
    {
        return count($this->deals);
    }
    
    public function countByStage(string $stage): int
    {
        return count($this->findByStage($stage));
    }
    
    public function getTotalValue(): Money
    {
        $total = 0;
        foreach ($this->deals as $deal) {
            $total += $deal->getAmount()->getFloatAmount();
        }
        return Money::fromFloat($total);
    }
}

// ==================== СЕРВИСЫ ====================

class AuthService
{
    private UserRepository $userRepository;
    
    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }
    
    public function login(string $email, string $password): ?User
    {
        $user = $this->userRepository->findByEmail($email);
        
        if (!$user || !$user->verifyPassword($password)) {
            return null;
        }
        
        $_SESSION[SESSION_KEY] = [
            'user_id' => $user->getId(),
            'email' => $user->getEmail()->getValue(),
            'name' => $user->getFullName(),
            'role' => $user->getRole(),
            'login_time' => time(),
        ];
        
        return $user;
    }
    
    public function logout(): void
    {
        unset($_SESSION[SESSION_KEY]);
    }
    
    public function getCurrentUser(): ?array
    {
        return $_SESSION[SESSION_KEY] ?? null;
    }
    
    public function isLoggedIn(): bool
    {
        return isset($_SESSION[SESSION_KEY]);
    }
    
    public function requireAuth(): void
    {
        if (!$this->isLoggedIn()) {
            header('Location: ?page=login');
            exit;
        }
    }
    
    public function requireRole(string $role): void
    {
        $this->requireAuth();
        
        $user = $this->getCurrentUser();
        if ($user['role'] !== $role) {
            header('Location: ?page=dashboard');
            exit;
        }
    }
}

class LeadService
{
    private LeadRepository $leadRepository;
    private UserRepository $userRepository;
    
    public function __construct(LeadRepository $leadRepository, UserRepository $userRepository)
    {
        $this->leadRepository = $leadRepository;
        $this->userRepository = $userRepository;
    }
    
    public function createLead(array $data): Lead
    {
        $leadId = 'lead_' . uniqid();
        
        try {
            $lead = new Lead(
                $leadId,
                $data['title'] ?? 'Новый лид',
                $data['contact_name'],
                new Email($data['contact_email']),
                new LeadSource($data['source'] ?? 'website'),
                isset($data['contact_phone']) ? new Phone($data['contact_phone']) : null,
                $data['company'] ?? null
            );
            
            if (isset($data['estimated_value']) && $data['estimated_value'] > 0) {
                $lead->setEstimatedValue(Money::fromFloat((float)$data['estimated_value']));
            }
            
            if (isset($data['assigned_to'])) {
                $lead->assignTo($data['assigned_to']);
            }
            
            $lead->addNote($data['notes'] ?? 'Лид создан через веб-интерфейс');
            
            $this->leadRepository->save($lead);
            return $lead;
            
        } catch (\Exception $e) {
            throw new \RuntimeException("Ошибка создания лида: " . $e->getMessage());
        }
    }
    
    public function updateLead(string $id, array $data): ?Lead
    {
        $lead = $this->leadRepository->findById($id);
        
        if (!$lead) {
            return null;
        }
        
        $lead->update($data);
        
        if (isset($data['status'])) {
            $lead->changeStatus(new LeadStatus($data['status']));
        }
        
        if (isset($data['estimated_value'])) {
            $value = $data['estimated_value'] ? Money::fromFloat((float)$data['estimated_value']) : null;
            $lead->setEstimatedValue($value);
        }
        
        if (isset($data['assigned_to'])) {
            $lead->assignTo($data['assigned_to']);
        }
        
        if (isset($data['notes']) && $data['notes']) {
            $lead->addNote($data['notes']);
        }
        
        $this->leadRepository->save($lead);
        return $lead;
    }
    
    public function deleteLead(string $id): bool
    {
        return $this->leadRepository->delete($id);
    }
    
    public function getLeadStats(): array
    {
        return [
            'total' => $this->leadRepository->count(),
            'new' => $this->leadRepository->countByStatus('new'),
            'in_progress' => $this->leadRepository->countByStatus('in_progress'),
            'qualified' => $this->leadRepository->countByStatus('qualified'),
            'converted' => $this->leadRepository->countByStatus('converted'),
            'disqualified' => $this->leadRepository->countByStatus('disqualified'),
        ];
    }
    
    public function getAllLeads(): array
    {
        return $this->leadRepository->findAll();
    }
}

class DealService
{
    private DealRepository $dealRepository;
    private LeadRepository $leadRepository;
    
    public function __construct(DealRepository $dealRepository, LeadRepository $leadRepository)
    {
        $this->dealRepository = $dealRepository;
        $this->leadRepository = $leadRepository;
    }
    
    public function createDeal(array $data): Deal
    {
        $dealId = 'deal_' . uniqid();
        
        $deal = new Deal(
            $dealId,
            $data['title'] ?? 'Новая сделка',
            Money::fromFloat((float)($data['amount'] ?? 0)),
            $data['owner_id'],
            $data['lead_id']
        );
        
        if (isset($data['stage'])) {
            $deal->updateStage($data['stage']);
        }
        
        $this->dealRepository->save($deal);
        return $deal;
    }
    
    public function convertLeadToDeal(string $leadId, string $ownerId): ?Deal
    {
        $lead = $this->leadRepository->findById($leadId);
        
        if (!$lead || $lead->getStatus()->getValue() !== 'qualified') {
            return null;
        }
        
        $deal = new Deal(
            'deal_' . uniqid(),
            'Сделка по лиду: ' . $lead->getTitle(),
            $lead->getEstimatedValue() ?? Money::fromFloat(0),
            $ownerId,
            $leadId
        );
        
        $lead->changeStatus(new LeadStatus('converted'));
        $this->leadRepository->save($lead);
        $this->dealRepository->save($deal);
        
        return $deal;
    }
    
    public function getDealStats(): array
    {
        return [
            'total' => $this->dealRepository->count(),
            'total_value' => $this->dealRepository->getTotalValue(),
            'prospecting' => $this->dealRepository->countByStage('prospecting'),
            'qualification' => $this->dealRepository->countByStage('qualification'),
            'proposal' => $this->dealRepository->countByStage('proposal'),
            'negotiation' => $this->dealRepository->countByStage('negotiation'),
            'closed_won' => $this->dealRepository->countByStage('closed_won'),
            'closed_lost' => $this->dealRepository->countByStage('closed_lost'),
        ];
    }
    
    public function getAllDeals(): array
    {
        return $this->dealRepository->findAll();
    }
}

// ==================== ИНИЦИАЛИЗАЦИЯ СЕРВИСОВ ====================

$userRepository = new UserRepository();
$leadRepository = new LeadRepository();
$dealRepository = new DealRepository();

$authService = new AuthService($userRepository);
$leadService = new LeadService($leadRepository, $userRepository);
$dealService = new DealService($dealRepository, $leadRepository);

// ==================== ОБРАБОТКА ЗАПРОСОВ ====================

$action = $_GET['action'] ?? null;
$page = $_GET['page'] ?? 'dashboard';

// Обработка POST запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentUser = $authService->getCurrentUser();
    
    switch ($_POST['_action'] ?? '') {
        case 'login':
            $user = $authService->login($_POST['email'], $_POST['password']);
            if ($user) {
                header('Location: ?page=dashboard');
                exit;
            } else {
                $error = 'Неверный email или пароль';
            }
            break;
            
        case 'logout':
            $authService->logout();
            header('Location: ?page=login');
            exit;
            
        case 'create_lead':
            $authService->requireAuth();
            try {
                $data = $_POST;
                $data['assigned_to'] = $currentUser['user_id'];
                $leadService->createLead($data);
                header('Location: ?page=leads');
                exit;
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
            break;
            
        case 'update_lead':
            $authService->requireAuth();
            $leadService->updateLead($_POST['id'], $_POST);
            header('Location: ?page=lead&id=' . $_POST['id']);
            exit;
            
        case 'delete_lead':
            $authService->requireAuth();
            $leadService->deleteLead($_POST['id']);
            header('Location: ?page=leads');
            exit;
            
        case 'convert_to_deal':
            $authService->requireAuth();
            $deal = $dealService->convertLeadToDeal($_POST['lead_id'], $currentUser['user_id']);
            if ($deal) {
                header('Location: ?page=deal&id=' . $deal->getId());
                exit;
            } else {
                $error = 'Невозможно конвертировать лид';
            }
            break;
            
        case 'create_deal':
            $authService->requireAuth();
            $data = $_POST;
            $data['owner_id'] = $currentUser['user_id'];
            $dealService->createDeal($data);
            header('Location: ?page=deals');
            exit;
    }
}

// ==================== ВЕБ-ИНТЕРФЕЙС ====================

function renderHead(string $title = ''): void
{
    ?>
    <!DOCTYPE html>
    <html lang="ru" class="h-full bg-gray-50">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title ? $title . ' - ' . APP_NAME : APP_NAME) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        spacing: {
                            '0.5': '0.125rem',
                            '0.75': '0.1875rem',
                        },
                        fontSize: {
                            '2xs': '0.625rem',
                            '3xs': '0.5rem',
                        }
                    }
                }
            }
        </script>
        <style>
            .sidebar-item.active { background-color: #3b82f6; color: white; }
            .sidebar-item:hover:not(.active) { background-color: #f3f4f6; }
            .badge { font-size: 0.75rem; padding: 0.125rem 0.5rem; border-radius: 9999px; }
            .status-badge { display: inline-flex; align-items: center; padding: 0.15rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500; }
            .compact-table th { padding: 0.375rem 0.75rem !important; font-size: 0.75rem !important; }
            .compact-table td { padding: 0.375rem 0.75rem !important; font-size: 0.8125rem !important; }
            .compact-card { padding: 0.75rem !important; }
            .compact-form .form-group { margin-bottom: 0.5rem !important; }
        </style>
    </head>
    <body class="h-full text-sm">
    <?php
}

function renderHeader(?array $user): void
{
    ?>
    <header class="bg-white border-b border-gray-200">
        <div class="px-4">
            <div class="flex justify-between items-center py-2">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-cube text-blue-600 text-lg"></i>
                    </div>
                    <div class="ml-3">
                        <h1 class="text-lg font-bold text-gray-900"><?= APP_NAME ?></h1>
                        <p class="text-xs text-gray-500">CRM v<?= APP_VERSION ?></p>
                    </div>
                </div>
                
                <?php if ($user): ?>
                <div class="flex items-center space-x-3">
                    <div class="text-right hidden sm:block">
                        <p class="text-xs font-medium text-gray-900"><?= htmlspecialchars($user['name']) ?></p>
                        <p class="text-2xs text-gray-500"><?= htmlspecialchars(ucfirst($user['role'])) ?></p>
                    </div>
                    <div class="relative">
                        <button onclick="document.getElementById('user-menu').classList.toggle('hidden')" 
                                class="flex items-center text-sm rounded-full focus:outline-none">
                            <div class="h-7 w-7 rounded-full bg-blue-100 flex items-center justify-center">
                                <i class="fas fa-user text-blue-600 text-sm"></i>
                            </div>
                        </button>
                        <div id="user-menu" class="hidden absolute right-0 mt-2 w-40 bg-white rounded-md shadow-lg py-1 z-10 border border-gray-100">
                            <form method="POST">
                                <input type="hidden" name="_action" value="logout">
                                <button type="submit" class="block w-full text-left px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-sign-out-alt mr-1.5 text-xs"></i>Выйти
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <?php
}

function renderSidebar(?array $user, string $currentPage): void
{
    if (!$user) return;
    
    $menuItems = [
        'dashboard' => ['icon' => 'tachometer-alt', 'label' => 'Дашборд'],
        'leads' => ['icon' => 'users', 'label' => 'Лиды'],
        'deals' => ['icon' => 'handshake', 'label' => 'Сделки'],
        'analytics' => ['icon' => 'chart-bar', 'label' => 'Аналитика'],
    ];
    
    if ($user['role'] === 'admin') {
        $menuItems['users'] = ['icon' => 'user-cog', 'label' => 'Пользователи'];
    }
    
    ?>
    <div class="flex">
        <div class="w-56 bg-white border-r border-gray-200 min-h-[calc(100vh-3.5rem)]">
            <nav class="mt-3 px-2">
                <?php foreach ($menuItems as $page => $item): ?>
                <a href="?page=<?= $page ?>" 
                   class="sidebar-item group flex items-center px-2 py-2 text-xs font-medium rounded mb-0.5 
                          <?= $currentPage === $page ? 'active' : 'text-gray-600 hover:text-gray-900' ?>">
                    <i class="fas fa-<?= $item['icon'] ?> mr-2.5 flex-shrink-0 w-4 text-center text-xs"></i>
                    <?= $item['label'] ?>
                </a>
                <?php endforeach; ?>
            </nav>
            
            <div class="mt-6 px-3">
                <div class="bg-blue-50 rounded p-3 border border-blue-100">
                    <h3 class="text-xs font-medium text-blue-800">Статистика</h3>
                    <p class="mt-0.5 text-2xs text-blue-700">
                        Система управления на Clean Architecture
                    </p>
                </div>
            </div>
        </div>
        
        <div class="flex-1">
    <?php
}

function renderFooter(): void
{
    ?>
        </div> <!-- закрываем flex-1 -->
    </div> <!-- закрываем flex -->
    <footer class="bg-white border-t border-gray-200 py-2">
        <div class="px-4">
            <p class="text-center text-xs text-gray-500">
                &copy; <?= date('Y') ?> <?= APP_NAME ?>. Все права защищены.
            </p>
        </div>
    </footer>
    <script>
        // Закрытие меню при клике вне его
        document.addEventListener('click', function(event) {
            const menu = document.getElementById('user-menu');
            const button = event.target.closest('button');
            
            if (menu && !button && !menu.contains(event.target)) {
                menu.classList.add('hidden');
            }
        });
    </script>
    </body>
    </html>
    <?php
}

function renderLoginPage(AuthService $auth): void
{
    global $error;
    
    renderHead('Вход в систему');
    ?>
    <div class="min-h-screen flex items-center justify-center bg-gray-50 py-8 px-4">
        <div class="max-w-sm w-full">
            <div class="text-center mb-6">
                <div class="mx-auto h-10 w-10 flex items-center justify-center rounded-md bg-blue-100 inline-block">
                    <i class="fas fa-cube text-blue-600 text-xl"></i>
                </div>
                <h2 class="mt-3 text-xl font-bold text-gray-900">
                    Вход в CRM
                </h2>
                <p class="mt-1 text-xs text-gray-600">
                    Используйте свои учетные данные
                </p>
            </div>
            
            <?php if (isset($error)): ?>
            <div class="bg-red-50 border-l-3 border-red-400 p-3 mb-4 rounded">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-circle text-red-400 text-sm"></i>
                    </div>
                    <div class="ml-2">
                        <p class="text-xs text-red-700"><?= htmlspecialchars($error) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <form class="space-y-4" method="POST">
                <input type="hidden" name="_action" value="login">
                <div class="space-y-3">
                    <div>
                        <label for="email" class="sr-only">Email</label>
                        <input id="email" name="email" type="email" required 
                               class="appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                               placeholder="Email адрес" value="manager@crm.local">
                    </div>
                    <div>
                        <label for="password" class="sr-only">Пароль</label>
                        <input id="password" name="password" type="password" required 
                               class="appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                               placeholder="Пароль" value="manager123">
                    </div>
                </div>

                <div>
                    <button type="submit" 
                            class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-blue-500">
                        <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                            <i class="fas fa-sign-in-alt text-blue-500 group-hover:text-blue-400 text-xs"></i>
                        </span>
                        Войти в систему
                    </button>
                </div>
                
                <div class="text-xs text-center text-gray-600 pt-2 border-t border-gray-100">
                    <p>Тестовые учетные записи:</p>
                    <p class="text-2xs mt-0.5">Админ: admin@crm.local / admin123</p>
                    <p class="text-2xs">Менеджер: manager@crm.local / manager123</p>
                </div>
            </form>
        </div>
    </div>
    <?php
    renderFooter();
}

function renderDashboardPage(
    AuthService $auth, 
    LeadService $leadService, 
    DealService $dealService, 
    UserRepository $userRepository,
    LeadRepository $leadRepository,
    DealRepository $dealRepository
): void {
    $currentUser = $auth->getCurrentUser();
    $leadStats = $leadService->getLeadStats();
    $dealStats = $dealService->getDealStats();
    $allLeads = $leadRepository->findAll();
    $allDeals = $dealRepository->findAll();
    
    renderHead('Дашборд');
    renderHeader($currentUser);
    renderSidebar($currentUser, 'dashboard');
    ?>
    <div class="p-4">
        <div class="mb-4">
            <h2 class="text-lg font-bold text-gray-900">Дашборд</h2>
            <p class="text-xs text-gray-600">Обзорная информация и ключевые метрики</p>
        </div>
        
        <!-- Статистика -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <div class="bg-white rounded border border-gray-200 p-3">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="h-8 w-8 rounded bg-blue-100 flex items-center justify-center">
                            <i class="fas fa-users text-blue-600 text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-xs font-medium text-gray-900">Всего лидов</h3>
                        <p class="text-xl font-bold text-gray-900"><?= $leadStats['total'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded border border-gray-200 p-3">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="h-8 w-8 rounded bg-green-100 flex items-center justify-center">
                            <i class="fas fa-handshake text-green-600 text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-xs font-medium text-gray-900">Всего сделок</h3>
                        <p class="text-xl font-bold text-gray-900"><?= $dealStats['total'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded border border-gray-200 p-3">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="h-8 w-8 rounded bg-yellow-100 flex items-center justify-center">
                            <i class="fas fa-chart-line text-yellow-600 text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-xs font-medium text-gray-900">Оборот</h3>
                        <p class="text-xl font-bold text-gray-900"><?= $dealStats['total_value'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded border border-gray-200 p-3">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="h-8 w-8 rounded bg-purple-100 flex items-center justify-center">
                            <i class="fas fa-user-friends text-purple-600 text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-xs font-medium text-gray-900">Пользователи</h3>
                        <p class="text-xl font-bold text-gray-900"><?= $userRepository->count() ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Статусы лидов и сделок -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <div class="bg-white rounded border border-gray-200 compact-card">
                <div class="pb-2 border-b border-gray-200">
                    <h3 class="text-sm font-medium text-gray-900">Статусы лидов</h3>
                </div>
                <div class="pt-2">
                    <div class="space-y-2">
                        <?php foreach ($leadStats as $status => $count): 
                            if (!in_array($status, ['new', 'in_progress', 'qualified', 'converted', 'disqualified'])) continue;
                            $statusObj = new LeadStatus($status);
                        ?>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <span class="status-badge bg-<?= $statusObj->getColor() ?>-100 text-<?= $statusObj->getColor() ?>-800">
                                    <?= $statusObj->getName() ?>
                                </span>
                            </div>
                            <div class="text-sm font-semibold text-gray-900"><?= $count ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- Стадии сделок -->
            <div class="bg-white rounded border border-gray-200 compact-card">
                <div class="pb-2 border-b border-gray-200">
                    <h3 class="text-sm font-medium text-gray-900">Стадии сделок</h3>
                </div>
                <div class="pt-2">
                    <div class="space-y-2">
                        <?php foreach ($dealStats as $stage => $count): 
                            if (!in_array($stage, ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'])) continue;
                            $stageColors = [
                                'prospecting' => 'gray',
                                'qualification' => 'blue',
                                'proposal' => 'yellow',
                                'negotiation' => 'purple',
                                'closed_won' => 'green',
                                'closed_lost' => 'red',
                            ];
                        ?>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <span class="status-badge bg-<?= $stageColors[$stage] ?>-100 text-<?= $stageColors[$stage] ?>-800">
                                    <?= ucfirst(str_replace('_', ' ', $stage)) ?>
                                </span>
                            </div>
                            <div class="text-sm font-semibold text-gray-900"><?= $count ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Последние лиды -->
        <div class="bg-white rounded border border-gray-200 mb-4">
            <div class="px-3 py-2 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-sm font-medium text-gray-900">Последние лиды</h3>
                <a href="?page=leads" class="text-xs text-blue-600 hover:text-blue-800">Все лиды →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 compact-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Клиент</th>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Статус</th>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Источник</th>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Бюджет</th>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Дата</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach (array_slice($allLeads, 0, 5) as $lead): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-7 w-7 rounded-full bg-blue-100 flex items-center justify-center">
                                        <i class="fas fa-user text-blue-600 text-xs"></i>
                                    </div>
                                    <div class="ml-2">
                                        <div class="text-xs font-medium text-gray-900"><?= htmlspecialchars($lead->getContactName()) ?></div>
                                        <div class="text-2xs text-gray-500 truncate max-w-[120px]"><?= htmlspecialchars($lead->getTitle()) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap">
                                <?php $status = $lead->getStatus(); ?>
                                <span class="status-badge bg-<?= $status->getColor() ?>-100 text-<?= $status->getColor() ?>-800">
                                    <?= $status->getName() ?>
                                </span>
                            </td>
                            <td class="whitespace-nowrap text-xs text-gray-500">
                                <i class="fas fa-<?= $lead->getSource()->getIcon() ?> mr-0.5 text-2xs"></i>
                                <?= $lead->getSource()->getName() ?>
                            </td>
                            <td class="whitespace-nowrap text-xs font-medium">
                                <?= $lead->getEstimatedValue() ?? '—' ?>
                            </td>
                            <td class="whitespace-nowrap text-xs text-gray-500">
                                <?= $lead->getCreatedAt()->format('d.m.Y') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Быстрые действия -->
        <div class="bg-white rounded border border-gray-200 p-3">
            <h3 class="text-sm font-medium text-gray-900 mb-2">Быстрые действия</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                <a href="?page=create_lead" 
                   class="flex items-center justify-center p-2 border border-dashed border-gray-300 rounded hover:border-blue-500 hover:bg-blue-50 transition-colors text-xs">
                    <i class="fas fa-plus text-gray-400 mr-1.5 text-xs"></i>
                    <span class="text-gray-700">Добавить лид</span>
                </a>
                <a href="?page=create_deal" 
                   class="flex items-center justify-center p-2 border border-dashed border-gray-300 rounded hover:border-green-500 hover:bg-green-50 transition-colors text-xs">
                    <i class="fas fa-handshake text-gray-400 mr-1.5 text-xs"></i>
                    <span class="text-gray-700">Создать сделку</span>
                </a>
                <a href="?page=analytics" 
                   class="flex items-center justify-center p-2 border border-dashed border-gray-300 rounded hover:border-purple-500 hover:bg-purple-50 transition-colors text-xs">
                    <i class="fas fa-chart-bar text-gray-400 mr-1.5 text-xs"></i>
                    <span class="text-gray-700">Отчеты</span>
                </a>
            </div>
        </div>
    </div>
    <?php
    renderFooter();
}

function renderLeadsPage(
    AuthService $auth, 
    LeadService $leadService, 
    LeadRepository $leadRepository, 
    UserRepository $userRepository
): void {
    $currentUser = $auth->getCurrentUser();
    $allLeads = $leadRepository->findAll();
    $statusFilter = $_GET['status'] ?? null;
    
    if ($statusFilter) {
        $allLeads = $leadRepository->findByStatus($statusFilter);
    }
    
    renderHead('Лиды');
    renderHeader($currentUser);
    renderSidebar($currentUser, 'leads');
    ?>
    <div class="p-4">
        <div class="mb-4 flex justify-between items-center">
            <div>
                <h2 class="text-lg font-bold text-gray-900">Лиды</h2>
                <p class="text-xs text-gray-600">Управление потенциальными клиентами</p>
            </div>
            <a href="?page=create_lead" 
               class="inline-flex items-center px-3 py-1.5 border border-transparent rounded shadow-sm text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-1 focus:ring-offset-1 focus:ring-blue-500">
                <i class="fas fa-plus mr-1.5 text-xs"></i>Добавить лид
            </a>
        </div>
        
        <!-- Фильтры -->
        <div class="bg-white rounded border border-gray-200 p-3 mb-3">
            <div class="flex flex-wrap gap-1.5">
                <a href="?page=leads" 
                   class="px-2 py-0.5 rounded-full text-xs <?= !$statusFilter ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800 hover:bg-gray-200' ?>">
                    Все (<?= $leadRepository->count() ?>)
                </a>
                <?php foreach (['new', 'in_progress', 'qualified', 'converted', 'disqualified'] as $status): 
                    $statusObj = new LeadStatus($status);
                ?>
                <a href="?page=leads&status=<?= $status ?>" 
                   class="px-2 py-0.5 rounded-full text-xs <?= $statusFilter === $status ? 'bg-' . $statusObj->getColor() . '-100 text-' . $statusObj->getColor() . '-800' : 'bg-gray-100 text-gray-800 hover:bg-gray-200' ?>">
                    <?= $statusObj->getName() ?> (<?= $leadRepository->countByStatus($status) ?>)
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Таблица лидов -->
        <div class="bg-white rounded border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 compact-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Клиент</th>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Контакты</th>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Статус</th>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Бюджет</th>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Дата</th>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($allLeads as $lead): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-7 w-7 rounded-full bg-blue-100 flex items-center justify-center">
                                        <i class="fas fa-user text-blue-600 text-xs"></i>
                                    </div>
                                    <div class="ml-2">
                                        <div class="text-xs font-medium text-gray-900"><?= htmlspecialchars($lead->getContactName()) ?></div>
                                        <div class="text-2xs text-gray-500"><?= htmlspecialchars($lead->getTitle()) ?></div>
                                        <?php if ($lead->getCompany()): ?>
                                        <div class="text-2xs text-gray-400"><?= htmlspecialchars($lead->getCompany()) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap">
                                <div class="text-xs text-gray-900"><?= htmlspecialchars((string)$lead->getContactEmail()) ?></div>
                                <?php if ($lead->getContactPhone()): ?>
                                <div class="text-xs text-gray-500"><?= $lead->getContactPhone() ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="whitespace-nowrap">
                                <?php $status = $lead->getStatus(); ?>
                                <span class="status-badge bg-<?= $status->getColor() ?>-100 text-<?= $status->getColor() ?>-800">
                                    <?= $status->getName() ?>
                                </span>
                            </td>
                            <td class="whitespace-nowrap text-xs font-medium">
                                <?= $lead->getEstimatedValue() ?? '—' ?>
                            </td>
                            <td class="whitespace-nowrap text-xs text-gray-500">
                                <?= $lead->getCreatedAt()->format('d.m.Y') ?>
                            </td>
                            <td class="whitespace-nowrap text-xs font-medium">
                                <div class="flex space-x-1.5">
                                    <a href="?page=lead&id=<?= $lead->getId() ?>" 
                                       class="text-blue-600 hover:text-blue-900" title="Просмотр">
                                        <i class="fas fa-eye text-xs"></i>
                                    </a>
                                    <a href="?page=edit_lead&id=<?= $lead->getId() ?>" 
                                       class="text-yellow-600 hover:text-yellow-900" title="Редактировать">
                                        <i class="fas fa-edit text-xs"></i>
                                    </a>
                                    <?php if ($lead->getStatus()->getValue() === 'qualified'): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="_action" value="convert_to_deal">
                                        <input type="hidden" name="lead_id" value="<?= $lead->getId() ?>">
                                        <button type="submit" class="text-green-600 hover:text-green-900" title="Конвертировать в сделку">
                                            <i class="fas fa-handshake text-xs"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (empty($allLeads)): ?>
            <div class="text-center py-6">
                <i class="fas fa-users text-gray-300 text-2xl mb-2"></i>
                <p class="text-sm text-gray-500">Лиды не найдены</p>
                <a href="?page=create_lead" class="text-blue-600 hover:text-blue-800 text-xs">Создать первый лид</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    renderFooter();
}

function renderCreateLeadPage(AuthService $auth): void
{
    $currentUser = $auth->getCurrentUser();
    
    renderHead('Новый лид');
    renderHeader($currentUser);
    renderSidebar($currentUser, 'leads');
    ?>
    <div class="p-4">
        <div class="max-w-3xl mx-auto">
            <div class="mb-4">
                <h2 class="text-lg font-bold text-gray-900">Новый лид</h2>
                <p class="text-xs text-gray-600">Заполните информацию о потенциальном клиенте</p>
            </div>
            
            <div class="bg-white rounded border border-gray-200 p-4 compact-form">
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="_action" value="create_lead">
                    
                    <div class="form-group">
                        <label for="title" class="block text-xs font-medium text-gray-700 mb-1">Название лида *</label>
                        <input type="text" id="title" name="title" required 
                               class="block w-full border border-gray-300 rounded shadow-sm py-1.5 px-2.5 focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                               placeholder="Например: Запрос на интеграцию CRM">
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="form-group">
                            <label for="contact_name" class="block text-xs font-medium text-gray-700 mb-1">Имя контакта *</label>
                            <input type="text" id="contact_name" name="contact_name" required 
                                   class="block w-full border border-gray-300 rounded shadow-sm py-1.5 px-2.5 focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                                   placeholder="Иван Иванов">
                        </div>
                        
                        <div class="form-group">
                            <label for="contact_email" class="block text-xs font-medium text-gray-700 mb-1">Email *</label>
                            <input type="email" id="contact_email" name="contact_email" required 
                                   class="block w-full border border-gray-300 rounded shadow-sm py-1.5 px-2.5 focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                                   placeholder="ivan@example.com">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="form-group">
                            <label for="contact_phone" class="block text-xs font-medium text-gray-700 mb-1">Телефон</label>
                            <input type="tel" id="contact_phone" name="contact_phone" 
                                   class="block w-full border border-gray-300 rounded shadow-sm py-1.5 px-2.5 focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                                   placeholder="+7 (912) 345-67-89">
                        </div>
                        
                        <div class="form-group">
                            <label for="company" class="block text-xs font-medium text-gray-700 mb-1">Компания</label>
                            <input type="text" id="company" name="company" 
                                   class="block w-full border border-gray-300 rounded shadow-sm py-1.5 px-2.5 focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                                   placeholder="ООО 'Рога и копыта'">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="form-group">
                            <label for="source" class="block text-xs font-medium text-gray-700 mb-1">Источник</label>
                            <select id="source" name="source" 
                                    class="block w-full border border-gray-300 rounded shadow-sm py-1.5 px-2.5 focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="website">Сайт</option>
                                <option value="phone">Телефон</option>
                                <option value="email">Email</option>
                                <option value="referral">Рекомендация</option>
                                <option value="social">Соцсети</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="estimated_value" class="block text-xs font-medium text-gray-700 mb-1">Ожидаемый бюджет</label>
                            <input type="number" id="estimated_value" name="estimated_value" min="0" step="1000" 
                                   class="block w-full border border-gray-300 rounded shadow-sm py-1.5 px-2.5 focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                                   placeholder="50000">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes" class="block text-xs font-medium text-gray-700 mb-1">Заметки</label>
                        <textarea id="notes" name="notes" rows="2" 
                                  class="block w-full border border-gray-300 rounded shadow-sm py-1.5 px-2.5 focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                                  placeholder="Дополнительная информация о лиде"></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-2 pt-3 border-t border-gray-200">
                        <a href="?page=leads" 
                           class="px-3 py-1.5 border border-gray-300 rounded shadow-sm text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-1 focus:ring-offset-1 focus:ring-blue-500">
                            Отмена
                        </a>
                        <button type="submit" 
                                class="px-3 py-1.5 border border-transparent rounded shadow-sm text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-1 focus:ring-offset-1 focus:ring-blue-500">
                            Создать лид
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
    renderFooter();
}

function renderLeadDetailPage(
    AuthService $auth, 
    LeadRepository $leadRepository, 
    UserRepository $userRepository
): void {
    $leadId = $_GET['id'] ?? null;
    $lead = $leadId ? $leadRepository->findById($leadId) : null;
    
    if (!$lead) {
        header('Location: ?page=leads');
        exit;
    }
    
    $currentUser = $auth->getCurrentUser();
    
    renderHead('Лид: ' . $lead->getContactName());
    renderHeader($currentUser);
    renderSidebar($currentUser, 'leads');
    ?>
    <div class="p-4">
        <div class="max-w-6xl mx-auto">
            <div class="mb-4 flex justify-between items-start">
                <div>
                    <div class="flex items-center mb-1">
                        <h2 class="text-lg font-bold text-gray-900 mr-2"><?= htmlspecialchars($lead->getContactName()) ?></h2>
                        <?php $status = $lead->getStatus(); ?>
                        <span class="status-badge bg-<?= $status->getColor() ?>-100 text-<?= $status->getColor() ?>-800">
                            <?= $status->getName() ?>
                        </span>
                    </div>
                    <p class="text-xs text-gray-600"><?= htmlspecialchars($lead->getTitle()) ?></p>
                    <?php if ($lead->getCompany()): ?>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars($lead->getCompany()) ?></p>
                    <?php endif; ?>
                </div>
                
                <div class="flex space-x-1.5">
                    <a href="?page=edit_lead&id=<?= $lead->getId() ?>" 
                       class="inline-flex items-center px-2.5 py-1 border border-gray-300 rounded shadow-sm text-xs font-medium text-gray-700 bg-white hover:bg-gray-50">
                        <i class="fas fa-edit mr-1.5 text-xs"></i>Редактировать
                    </a>
                    
                    <?php if ($lead->getStatus()->getValue() === 'qualified'): ?>
                    <form method="POST">
                        <input type="hidden" name="_action" value="convert_to_deal">
                        <input type="hidden" name="lead_id" value="<?= $lead->getId() ?>">
                        <button type="submit" 
                                class="inline-flex items-center px-2.5 py-1 border border-transparent rounded shadow-sm text-xs font-medium text-white bg-green-600 hover:bg-green-700">
                            <i class="fas fa-handshake mr-1.5 text-xs"></i>В сделку
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <!-- Основная информация -->
                <div class="lg:col-span-2 space-y-4">
                    <div class="bg-white rounded border border-gray-200 compact-card">
                        <div class="pb-2 border-b border-gray-200">
                            <h3 class="text-sm font-medium text-gray-900">Информация о лиде</h3>
                        </div>
                        <div class="pt-2">
                            <dl class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <dt class="text-xs font-medium text-gray-500">Email</dt>
                                    <dd class="mt-0.5 text-sm text-gray-900"><?= htmlspecialchars((string)$lead->getContactEmail()) ?></dd>
                                </div>
                                
                                <?php if ($lead->getContactPhone()): ?>
                                <div>
                                    <dt class="text-xs font-medium text-gray-500">Телефон</dt>
                                    <dd class="mt-0.5 text-sm text-gray-900"><?= $lead->getContactPhone() ?></dd>
                                </div>
                                <?php endif; ?>
                                
                                <div>
                                    <dt class="text-xs font-medium text-gray-500">Источник</dt>
                                    <dd class="mt-0.5 text-sm text-gray-900">
                                        <i class="fas fa-<?= $lead->getSource()->getIcon() ?> mr-1 text-xs"></i>
                                        <?= $lead->getSource()->getName() ?>
                                    </dd>
                                </div>
                                
                                <div>
                                    <dt class="text-xs font-medium text-gray-500">Ожидаемый бюджет</dt>
                                    <dd class="mt-0.5 text-sm text-gray-900 font-medium">
                                        <?= $lead->getEstimatedValue() ?? 'Не указан' ?>
                                    </dd>
                                </div>
                                
                                <div>
                                    <dt class="text-xs font-medium text-gray-500">Дата создания</dt>
                                    <dd class="mt-0.5 text-sm text-gray-900"><?= $lead->getCreatedAt()->format('d.m.Y H:i') ?></dd>
                                </div>
                                
                                <div>
                                    <dt class="text-xs font-medium text-gray-500">Последнее обновление</dt>
                                    <dd class="mt-0.5 text-sm text-gray-900"><?= $lead->getUpdatedAt()->format('d.m.Y H:i') ?></dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                    
                    <!-- Заметки -->
                    <div class="bg-white rounded border border-gray-200 compact-card">
                        <div class="pb-2 border-b border-gray-200">
                            <h3 class="text-sm font-medium text-gray-900">Заметки</h3>
                        </div>
                        <div class="pt-2">
                            <div class="space-y-3">
                                <?php foreach (array_reverse($lead->getNotes()) as $note): ?>
                                <div class="border-l-2 border-blue-500 pl-3 py-1">
                                    <p class="text-sm text-gray-800"><?= htmlspecialchars($note['text']) ?></p>
                                    <p class="text-xs text-gray-500 mt-0.5"><?= $note['timestamp']->format('d.m.Y H:i') ?></p>
                                </div>
                                <?php endforeach; ?>
                                
                                <?php if (empty($lead->getNotes())): ?>
                                <p class="text-gray-500 text-center py-2 text-sm">Заметок пока нет</p>
                                <?php endif; ?>
                            </div>
                            
                            <form method="POST" class="mt-4">
                                <input type="hidden" name="_action" value="update_lead">
                                <input type="hidden" name="id" value="<?= $lead->getId() ?>">
                                <div>
                                    <label for="new_note" class="sr-only">Новая заметка</label>
                                    <textarea id="new_note" name="notes" rows="1" 
                                              class="block w-full border border-gray-300 rounded shadow-sm py-1.5 px-2.5 focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                                              placeholder="Добавить заметку..."></textarea>
                                </div>
                                <div class="mt-1.5 flex justify-end">
                                    <button type="submit" 
                                            class="inline-flex items-center px-2.5 py-1 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700">
                                        Добавить заметку
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Боковая панель -->
                <div class="space-y-4">
                    <!-- Быстрые действия -->
                    <div class="bg-white rounded border border-gray-200 compact-card">
                        <div class="pb-2 border-b border-gray-200">
                            <h3 class="text-sm font-medium text-gray-900">Быстрые действия</h3>
                        </div>
                        <div class="pt-2">
                            <form method="POST" class="space-y-2.5">
                                <input type="hidden" name="_action" value="update_lead">
                                <input type="hidden" name="id" value="<?= $lead->getId() ?>">
                                
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Изменить статус</label>
                                    <select name="status" 
                                            class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 text-xs focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                            onchange="this.form.submit()">
                                        <?php foreach (['new', 'in_progress', 'qualified', 'converted', 'disqualified'] as $status): 
                                            $statusObj = new LeadStatus($status);
                                        ?>
                                        <option value="<?= $status ?>" <?= $lead->getStatus()->getValue() === $status ? 'selected' : '' ?>>
                                            <?= $statusObj->getName() ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Обновить бюджет</label>
                                    <div class="flex">
                                        <input type="number" name="estimated_value" 
                                               value="<?= $lead->getEstimatedValue() ? $lead->getEstimatedValue()->getFloatAmount() : '' ?>" 
                                               step="1000" min="0"
                                               class="flex-1 border border-gray-300 rounded-l shadow-sm py-1 px-2 text-xs focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <button type="submit" 
                                                class="bg-gray-100 border border-l-0 border-gray-300 rounded-r px-2 text-xs hover:bg-gray-200">
                                            OK
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Информация о назначении -->
                    <div class="bg-white rounded border border-gray-200 compact-card">
                        <div class="pb-2 border-b border-gray-200">
                            <h3 class="text-sm font-medium text-gray-900">Назначение</h3>
                        </div>
                        <div class="pt-2">
                            <?php if ($lead->getAssignedTo()): 
                                $user = $userRepository->findById($lead->getAssignedTo());
                            ?>
                            <div class="flex items-center">
                                <div class="flex-shrink-0 h-6 w-6 rounded-full bg-blue-100 flex items-center justify-center">
                                    <i class="fas fa-user text-blue-600 text-xs"></i>
                                </div>
                                <div class="ml-2">
                                    <p class="text-xs font-medium text-gray-900"><?= htmlspecialchars($user->getFullName()) ?></p>
                                    <p class="text-2xs text-gray-500"><?= htmlspecialchars(ucfirst($user->getRole())) ?></p>
                                </div>
                            </div>
                            <?php else: ?>
                            <p class="text-xs text-gray-500">Не назначен</p>
                            <form method="POST" class="mt-1">
                                <input type="hidden" name="_action" value="update_lead">
                                <input type="hidden" name="id" value="<?= $lead->getId() ?>">
                                <input type="hidden" name="assigned_to" value="<?= $currentUser['user_id'] ?>">
                                <button type="submit" 
                                        class="text-2xs text-blue-600 hover:text-blue-800">
                                    Назначить на себя
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    renderFooter();
}

function renderDealsPage(
    AuthService $auth, 
    DealRepository $dealRepository, 
    LeadRepository $leadRepository,
    UserRepository $userRepository
): void {
    $currentUser = $auth->getCurrentUser();
    $allDeals = $dealRepository->findAll();
    
    renderHead('Сделки');
    renderHeader($currentUser);
    renderSidebar($currentUser, 'deals');
    ?>
    <div class="p-4">
        <div class="mb-4 flex justify-between items-center">
            <div>
                <h2 class="text-lg font-bold text-gray-900">Сделки</h2>
                <p class="text-xs text-gray-600">Управление коммерческими предложениями</p>
            </div>
            <a href="?page=create_deal" 
               class="inline-flex items-center px-3 py-1.5 border border-transparent rounded shadow-sm text-xs font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-1 focus:ring-offset-1 focus:ring-green-500">
                <i class="fas fa-plus mr-1.5 text-xs"></i>Новая сделка
            </a>
        </div>
        
        <!-- Статистика сделок -->
        <?php
        $dealStats = [
            'prospecting' => $dealRepository->countByStage('prospecting'),
            'qualification' => $dealRepository->countByStage('qualification'),
            'proposal' => $dealRepository->countByStage('proposal'),
            'negotiation' => $dealRepository->countByStage('negotiation'),
            'closed_won' => $dealRepository->countByStage('closed_won'),
            'closed_lost' => $dealRepository->countByStage('closed_lost'),
        ];
        ?>
        <div class="grid grid-cols-3 md:grid-cols-6 gap-2 mb-3">
            <?php foreach ($dealStats as $stage => $count): 
                $colors = [
                    'prospecting' => 'gray',
                    'qualification' => 'blue',
                    'proposal' => 'yellow',
                    'negotiation' => 'purple',
                    'closed_won' => 'green',
                    'closed_lost' => 'red',
                ];
            ?>
            <div class="bg-white rounded border border-gray-200 p-2 text-center">
                <div class="text-base font-bold text-<?= $colors[$stage] ?>-600"><?= $count ?></div>
                <div class="text-2xs text-gray-500 mt-0.5"><?= ucfirst(str_replace('_', ' ', $stage)) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Таблица сделок -->
        <div class="bg-white rounded border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 compact-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Название</th>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Стадия</th>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Сумма</th>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Вероятность</th>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Владелец</th>
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Дата</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($allDeals as $deal): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="whitespace-nowrap">
                                <div class="text-xs font-medium text-gray-900"><?= htmlspecialchars($deal->getTitle()) ?></div>
                                <div class="text-2xs text-gray-500">
                                    <?php 
                                    $lead = $leadRepository->findById($deal->getLeadId());
                                    echo $lead ? htmlspecialchars($lead->getContactName()) : 'Лид не найден';
                                    ?>
                                </div>
                            </td>
                            <td class="whitespace-nowrap">
                                <span class="status-badge bg-<?= $deal->getStageColor() ?>-100 text-<?= $deal->getStageColor() ?>-800">
                                    <?= $deal->getStageName() ?>
                                </span>
                            </td>
                            <td class="whitespace-nowrap text-xs font-medium text-gray-900">
                                <?= $deal->getAmount() ?>
                            </td>
                            <td class="whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="w-16 bg-gray-200 rounded-full h-1.5 mr-2">
                                        <div class="bg-blue-600 h-1.5 rounded-full" style="width: <?= $deal->getProbability() ?>%"></div>
                                    </div>
                                    <span class="text-xs text-gray-900"><?= $deal->getProbability() ?>%</span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap text-xs text-gray-500">
                                <?php 
                                $owner = $userRepository->findById($deal->getOwnerId());
                                echo $owner ? htmlspecialchars($owner->getFirstName()) : 'Неизвестно';
                                ?>
                            </td>
                            <td class="whitespace-nowrap text-xs text-gray-500">
                                <?= $deal->getCreatedAt()->format('d.m.Y') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (empty($allDeals)): ?>
            <div class="text-center py-6">
                <i class="fas fa-handshake text-gray-300 text-2xl mb-2"></i>
                <p class="text-sm text-gray-500">Сделки не найдены</p>
                <a href="?page=create_deal" class="text-green-600 hover:text-green-800 text-xs">Создать первую сделку</a>
                <p class="text-xs text-gray-400 mt-1">Или конвертируйте квалифицированный лид в сделку</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    renderFooter();
}

function renderAnalyticsPage(
    AuthService $auth, 
    LeadService $leadService, 
    DealService $dealService
): void {
    $currentUser = $auth->getCurrentUser();
    $leadStats = $leadService->getLeadStats();
    $dealStats = $dealService->getDealStats();
    
    renderHead('Аналитика');
    renderHeader($currentUser);
    renderSidebar($currentUser, 'analytics');
    ?>
    <div class="p-4">
        <div class="mb-4">
            <h2 class="text-lg font-bold text-gray-900">Аналитика и отчеты</h2>
            <p class="text-xs text-gray-600">Ключевые метрики и статистика эффективности</p>
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <!-- График лидов -->
            <div class="bg-white rounded border border-gray-200 compact-card">
                <h3 class="text-sm font-medium text-gray-900 mb-3">Распределение лидов по статусам</h3>
                <div class="space-y-3">
                    <?php 
                    $leadStatuses = ['new', 'in_progress', 'qualified', 'converted', 'disqualified'];
                    $totalLeads = $leadStats['total'];
                    foreach ($leadStatuses as $status):
                        $count = $leadStats[$status];
                        $percentage = $totalLeads > 0 ? ($count / $totalLeads * 100) : 0;
                        $statusObj = new LeadStatus($status);
                    ?>
                    <div>
                        <div class="flex justify-between text-xs mb-1">
                            <span class="font-medium text-gray-700"><?= $statusObj->getName() ?></span>
                            <span class="text-gray-900"><?= $count ?> (<?= round($percentage, 1) ?>%)</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-1.5">
                            <div class="bg-<?= $statusObj->getColor() ?>-600 h-1.5 rounded-full" style="width: <?= $percentage ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- График сделок -->
            <div class="bg-white rounded border border-gray-200 compact-card">
                <h3 class="text-sm font-medium text-gray-900 mb-3">Распределение сделок по стадиям</h3>
                <div class="space-y-3">
                    <?php 
                    $stages = ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];
                    $totalDeals = $dealStats['total'];
                    foreach ($stages as $stage):
                        $count = $dealStats[$stage];
                        $percentage = $totalDeals > 0 ? ($count / $totalDeals * 100) : 0;
                        $colors = [
                            'prospecting' => 'gray',
                            'qualification' => 'blue',
                            'proposal' => 'yellow',
                            'negotiation' => 'purple',
                            'closed_won' => 'green',
                            'closed_lost' => 'red',
                        ];
                    ?>
                    <div>
                        <div class="flex justify-between text-xs mb-1">
                            <span class="font-medium text-gray-700"><?= ucfirst(str_replace('_', ' ', $stage)) ?></span>
                            <span class="text-gray-900"><?= $count ?> (<?= round($percentage, 1) ?>%)</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-1.5">
                            <div class="bg-<?= $colors[$stage] ?>-600 h-1.5 rounded-full" style="width: <?= $percentage ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Показатели эффективности -->
        <div class="bg-white rounded border border-gray-200 compact-card">
            <h3 class="text-sm font-medium text-gray-900 mb-4">Ключевые показатели</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="text-center p-3 border border-gray-200 rounded">
                    <div class="text-xl font-bold text-blue-600 mb-1">
                        <?= $totalLeads > 0 ? round(($leadStats['converted'] / $totalLeads * 100), 1) : 0 ?>%
                    </div>
                    <div class="text-xs text-gray-600">Конверсия лидов</div>
                    <div class="text-2xs text-gray-400 mt-0.5">лиды → сделки</div>
                </div>
                
                <div class="text-center p-3 border border-gray-200 rounded">
                    <div class="text-xl font-bold text-green-600 mb-1">
                        <?= $dealStats['total'] > 0 ? round(($dealStats['closed_won'] / $dealStats['total'] * 100), 1) : 0 ?>%
                    </div>
                    <div class="text-xs text-gray-600">Успешных сделок</div>
                    <div class="text-2xs text-gray-400 mt-0.5">win rate</div>
                </div>
                
                <div class="text-center p-3 border border-gray-200 rounded">
                    <div class="text-xl font-bold text-purple-600 mb-1">
                        <?= $dealStats['total_value'] ?>
                    </div>
                    <div class="text-xs text-gray-600">Общий оборот</div>
                    <div class="text-2xs text-gray-400 mt-0.5">все сделки</div>
                </div>
            </div>
        </div>
        
        <!-- Рекомендации -->
        <div class="bg-white rounded border border-gray-200 compact-card mt-4">
            <h3 class="text-sm font-medium text-gray-900 mb-3">Рекомендации</h3>
            <div class="space-y-2.5">
                <?php if ($leadStats['qualified'] > 0): ?>
                <div class="flex items-start">
                    <i class="fas fa-lightbulb text-yellow-500 mt-0.5 mr-2 text-xs"></i>
                    <div>
                        <p class="text-xs font-medium text-gray-900"><?= $leadStats['qualified'] ?> квалифицированных лида готовы к конвертации</p>
                        <p class="text-2xs text-gray-500">Рекомендуем создать сделки для этих лидов</p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($leadStats['new'] > 5): ?>
                <div class="flex items-start">
                    <i class="fas fa-exclamation-triangle text-red-500 mt-0.5 mr-2 text-xs"></i>
                    <div>
                        <p class="text-xs font-medium text-gray-900"><?= $leadStats['new'] ?> новых лидов требуют обработки</p>
                        <p class="text-2xs text-gray-500">Рекомендуем назначить ответственных менеджеров</p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($dealStats['negotiation'] > 0): ?>
                <div class="flex items-start">
                    <i class="fas fa-handshake text-green-500 mt-0.5 mr-2 text-xs"></i>
                    <div>
                        <p class="text-xs font-medium text-gray-900"><?= $dealStats['negotiation'] ?> сделок на стадии переговоров</p>
                        <p class="text-2xs text-gray-500">Высокая вероятность закрытия. Уделите внимание этим сделкам</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    renderFooter();
}

// ==================== ГЛАВНЫЙ РОУТЕР ====================

if (!$authService->isLoggedIn() && $page !== 'login') {
    $page = 'login';
}

switch ($page) {
    case 'login':
        renderLoginPage($authService);
        break;
        
    case 'dashboard':
        renderDashboardPage(
            $authService, 
            $leadService, 
            $dealService, 
            $userRepository,
            $leadRepository,
            $dealRepository
        );
        break;
        
    case 'leads':
        renderLeadsPage($authService, $leadService, $leadRepository, $userRepository);
        break;
        
    case 'lead':
        renderLeadDetailPage($authService, $leadRepository, $userRepository);
        break;
        
    case 'create_lead':
        renderCreateLeadPage($authService);
        break;
        
    case 'deals':
        renderDealsPage($authService, $dealRepository, $leadRepository, $userRepository);
        break;
        
    case 'analytics':
        renderAnalyticsPage($authService, $leadService, $dealService);
        break;
        
    default:
        if ($authService->isLoggedIn()) {
            renderDashboardPage(
                $authService, 
                $leadService, 
                $dealService, 
                $userRepository,
                $leadRepository,
                $dealRepository
            );
        } else {
            renderLoginPage($authService);
        }
        break;
}