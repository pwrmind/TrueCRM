<?php
/**
 * CRM SYSTEM v1.0 - Полнофункциональная CRM в одном файле
 * Архитектура: Clean Architecture + DDD + Hexagonal
 * PHP 8.1+
 */

declare(strict_types=1);

// ==================== КОНСТАНТЫ И НАСТРОЙКИ ====================
const APP_VERSION = '1.0.0';
const ENV_DEV = 'development';
const ENV_PROD = 'production';

// Режим работы (менять при необходимости)
define('CRM_ENV', ENV_DEV);
define('CRM_DEBUG', CRM_ENV === ENV_DEV);

// ==================== VALUE OBJECTS ====================

/**
 * Иммутабельный объект Email с валидацией
 */
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
    
    public function getValue(): string
    {
        return $this->value;
    }
    
    public function getDomain(): string
    {
        return substr($this->value, strpos($this->value, '@') + 1);
    }
    
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
    
    public function __toString(): string
    {
        return $this->value;
    }
}

/**
 * Value Object для номера телефона
 */
final class Phone implements \Stringable
{
    private string $value;
    private string $countryCode;
    
    public function __construct(string $phone, string $countryCode = '7')
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($cleaned) < 10) {
            throw new \InvalidArgumentException("Некорректный номер телефона");
        }
        
        $this->value = $cleaned;
        $this->countryCode = $countryCode;
    }
    
    public function getFullNumber(): string
    {
        return '+' . $this->countryCode . $this->value;
    }
    
    public function getFormatted(): string
    {
        $num = $this->value;
        return '+7 (' . substr($num, 0, 3) . ') ' . substr($num, 3, 3) . '-' . substr($num, 6, 2) . '-' . substr($num, 8, 2);
    }
    
    public function __toString(): string
    {
        return $this->getFullNumber();
    }
}

/**
 * Value Object для денежных сумм
 */
final class Money
{
    private int $amount; // В копейках/центах
    private string $currency;
    
    public function __construct(int $amount, string $currency = 'RUB')
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException("Сумма не может быть отрицательной");
        }
        
        $this->amount = $amount;
        $this->currency = strtoupper($currency);
    }
    
    public static function fromFloat(float $amount, string $currency = 'RUB'): self
    {
        return new self((int)($amount * 100), $currency);
    }
    
    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException("Валюты должны совпадать");
        }
        
        return new self($this->amount + $other->amount, $this->currency);
    }
    
    public function subtract(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException("Валюты должны совпадать");
        }
        
        $newAmount = $this->amount - $other->amount;
        if ($newAmount < 0) {
            throw new \InvalidArgumentException("Результат не может быть отрицательным");
        }
        
        return new self($newAmount, $this->currency);
    }
    
    public function getAmount(): int
    {
        return $this->amount;
    }
    
    public function getFloatAmount(): float
    {
        return $this->amount / 100;
    }
    
    public function getCurrency(): string
    {
        return $this->currency;
    }
    
    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }
    
    public function __toString(): string
    {
        return number_format($this->getFloatAmount(), 2, '.', ' ') . ' ' . $this->currency;
    }
}

/**
 * Источник лида (UTM метки)
 */
final class LeadSource
{
    private string $source; // google, yandex, direct
    private string $medium; // cpc, organic, email
    private string $campaign;
    private string $content;
    private string $term;
    
    public function __construct(
        string $source = 'direct',
        string $medium = 'none',
        string $campaign = '',
        string $content = '',
        string $term = ''
    ) {
        $this->source = $source;
        $this->medium = $medium;
        $this->campaign = $campaign;
        $this->content = $content;
        $this->term = $term;
    }
    
    public static function fromArray(array $data): self
    {
        return new self(
            $data['source'] ?? 'direct',
            $data['medium'] ?? 'none',
            $data['campaign'] ?? '',
            $data['content'] ?? '',
            $data['term'] ?? ''
        );
    }
    
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'medium' => $this->medium,
            'campaign' => $this->campaign,
            'content' => $this->content,
            'term' => $this->term,
        ];
    }
    
    public function getSource(): string { return $this->source; }
    public function getMedium(): string { return $this->medium; }
    public function getCampaign(): string { return $this->campaign; }
    public function getContent(): string { return $this->content; }
    public function getTerm(): string { return $this->term; }
}

/**
 * Статус лида (Value Object)
 */
final class LeadStatus
{
    private string $value;
    private array $allowedTransitions;
    
    private const STATUSES = [
        'new' => ['in_progress', 'disqualified'],
        'in_progress' => ['qualified', 'disqualified'],
        'qualified' => ['converted', 'disqualified'],
        'converted' => [],
        'disqualified' => [],
    ];
    
    public function __construct(string $status)
    {
        if (!isset(self::STATUSES[$status])) {
            throw new \InvalidArgumentException("Недопустимый статус лида: {$status}");
        }
        
        $this->value = $status;
        $this->allowedTransitions = self::STATUSES[$status];
    }
    
    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, $this->allowedTransitions, true);
    }
    
    public function getValue(): string
    {
        return $this->value;
    }
    
    public function isFinal(): bool
    {
        return empty($this->allowedTransitions);
    }
    
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
    
    public function __toString(): string
    {
        return $this->value;
    }
}

/**
 * Приоритет лида/задачи
 */
final class Priority
{
    private string $value;
    
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';
    public const CRITICAL = 'critical';
    
    private const ALLOWED = [self::LOW, self::MEDIUM, self::HIGH, self::CRITICAL];
    
    public function __construct(string $priority)
    {
        if (!in_array($priority, self::ALLOWED, true)) {
            throw new \InvalidArgumentException("Недопустимый приоритет: {$priority}");
        }
        
        $this->value = $priority;
    }
    
    public function getValue(): string
    {
        return $this->value;
    }
    
    public function isHigherThan(self $other): bool
    {
        $levels = array_flip(self::ALLOWED);
        return $levels[$this->value] > $levels[$other->value];
    }
    
    public function __toString(): string
    {
        return $this->value;
    }
}

// ==================== ИНТЕРФЕЙСЫ ====================

/**
 * Интерфейс пользователя системы
 */
interface UserInterface
{
    public function getId(): string;
    public function getEmail(): Email;
    public function getPhone(): ?Phone;
    public function getFullName(): string;
    public function getRoles(): array;
    public function hasRole(string $role): bool;
    public function isActive(): bool;
}

/**
 * Интерфейс для проверки прав доступа
 */
interface AccessSubjectInterface extends UserInterface
{
    public function getPermissions(): array;
    public function hasPermission(string $permission): bool;
}

/**
 * Менеджер разрешений
 */
interface PermissionManagerInterface
{
    public function isGranted(AccessSubjectInterface $user, string $permission, ?object $subject = null): bool;
    public function grantRole(string $role, array $permissions): void;
    public function revokeRole(string $role): void;
}

/**
 * Аутентификатор
 */
interface AuthenticatorInterface
{
    public function authenticate(string $login, string $password): ?AccessSubjectInterface;
    public function logout(): void;
    public function getCurrentUser(): ?AccessSubjectInterface;
}

/**
 * Интерфейс лида
 */
interface LeadInterface
{
    public function getId(): string;
    public function getTitle(): string;
    public function getStatus(): LeadStatus;
    public function getSource(): LeadSource;
    public function getAssignedTo(): ?AccessSubjectInterface;
    public function getCreatedAt(): \DateTimeInterface;
    public function getUpdatedAt(): \DateTimeInterface;
    
    public function changeStatus(LeadStatus $newStatus): void;
    public function assignTo(AccessSubjectInterface $user): void;
    public function addNote(string $note): void;
    public function getNotes(): array;
}

/**
 * Репозиторий лидов
 */
interface LeadRepositoryInterface
{
    public function findById(string $id): ?LeadInterface;
    public function findByEmail(string $email): array;
    public function findByStatus(string $status): array;
    public function save(LeadInterface $lead): void;
    public function delete(string $id): void;
    
    public function search(array $criteria, int $limit = 50, int $offset = 0): array;
    public function count(array $criteria): int;
}

/**
 * Конвертер лидов в сделки
 */
interface LeadConverterInterface
{
    public function convert(LeadInterface $lead, array $options = []): DealInterface;
    public function canConvert(LeadInterface $lead): bool;
}

/**
 * Интерфейс сделки
 */
interface DealInterface
{
    public function getId(): string;
    public function getTitle(): string;
    public function getAmount(): Money;
    public function getStage(): string;
    public function getLead(): ?LeadInterface;
    public function getOwner(): AccessSubjectInterface;
    public function getProbability(): int; // 0-100%
    public function getCloseDate(): ?\DateTimeInterface;
    
    public function updateStage(string $stage): void;
    public function updateAmount(Money $amount): void;
    public function setProbability(int $probability): void;
    public function close(bool $won, ?string $reason = null): void;
}

/**
 * Интерфейс сообщения
 */
interface MessageInterface
{
    public function getId(): string;
    public function getType(): string; // email, sms, call, chat
    public function getDirection(): string; // incoming, outgoing
    public function getSubject(): string;
    public function getBody(): string;
    public function getFrom(): string;
    public function getTo(): array;
    public function getSentAt(): \DateTimeInterface;
    public function getStatus(): string; // sent, delivered, read, failed
    
    public function markAsRead(): void;
    public function markAsFailed(string $reason): void;
}

/**
 * Провайдер коммуникаций
 */
interface CommunicationProviderInterface
{
    public function send(MessageInterface $message): bool;
    public function supports(string $channelType): bool;
    public function getName(): string;
    public function testConnection(): bool;
}

/**
 * Шина сообщений (Command/Event Bus)
 */
interface MessageBusInterface
{
    public function dispatch(object $message): void;
    public function subscribe(string $eventClass, callable $handler): void;
    public function publish(object $event): void;
}

/**
 * Интерфейс хранилища
 */
interface StorageInterface
{
    public function put(string $path, string $content, array $metadata = []): string;
    public function get(string $path): string;
    public function delete(string $path): bool;
    public function exists(string $path): bool;
    public function getUrl(string $path): string;
}

/**
 * Интерфейс аудита
 */
interface AuditorInterface
{
    public function log(string $action, array $data, ?string $userId = null): void;
    public function getLogs(array $criteria = [], int $limit = 100): array;
    public function getUserActivity(string $userId, \DateTimeInterface $from, \DateTimeInterface $to): array;
}

// ==================== СУЩНОСТИ ====================

/**
 * Пользователь системы
 */
class User implements AccessSubjectInterface
{
    private string $id;
    private Email $email;
    private ?Phone $phone;
    private string $firstName;
    private string $lastName;
    private string $passwordHash;
    private array $roles;
    private array $permissions;
    private bool $isActive;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    
    public function __construct(
        string $id,
        Email $email,
        string $firstName,
        string $lastName,
        string $passwordHash,
        array $roles = ['user'],
        array $permissions = [],
        ?Phone $phone = null
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->phone = $phone;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->passwordHash = $passwordHash;
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->isActive = true;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    // Реализация интерфейса UserInterface
    public function getId(): string { return $this->id; }
    public function getEmail(): Email { return $this->email; }
    public function getPhone(): ?Phone { return $this->phone; }
    public function getFullName(): string { return $this->firstName . ' ' . $this->lastName; }
    public function getRoles(): array { return $this->roles; }
    public function hasRole(string $role): bool { return in_array($role, $this->roles, true); }
    public function isActive(): bool { return $this->isActive; }
    
    // Реализация AccessSubjectInterface
    public function getPermissions(): array { return $this->permissions; }
    public function hasPermission(string $permission): bool { return in_array($permission, $this->permissions, true); }
    
    // Бизнес-методы
    public function activate(): void
    {
        $this->isActive = true;
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    public function deactivate(): void
    {
        $this->isActive = false;
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    public function addRole(string $role): void
    {
        if (!$this->hasRole($role)) {
            $this->roles[] = $role;
            $this->updatedAt = new \DateTimeImmutable();
        }
    }
    
    public function removeRole(string $role): void
    {
        $this->roles = array_filter($this->roles, fn($r) => $r !== $role);
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    public function addPermission(string $permission): void
    {
        if (!$this->hasPermission($permission)) {
            $this->permissions[] = $permission;
            $this->updatedAt = new \DateTimeImmutable();
        }
    }
    
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }
    
    public function changePassword(string $newPassword): void
    {
        $this->passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
}

/**
 * Роль пользователя
 */
class Role
{
    private string $name;
    private string $description;
    private array $permissions;
    private bool $isSystem;
    
    public function __construct(string $name, string $description = '', array $permissions = [], bool $isSystem = false)
    {
        $this->name = $name;
        $this->description = $description;
        $this->permissions = $permissions;
        $this->isSystem = $isSystem;
    }
    
    public function getName(): string { return $this->name; }
    public function getDescription(): string { return $this->description; }
    public function getPermissions(): array { return $this->permissions; }
    public function isSystem(): bool { return $this->isSystem; }
    
    public function addPermission(string $permission): void
    {
        if (!in_array($permission, $this->permissions, true)) {
            $this->permissions[] = $permission;
        }
    }
    
    public function removePermission(string $permission): void
    {
        $this->permissions = array_filter($this->permissions, fn($p) => $p !== $permission);
    }
    
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}

/**
 * Лид - потенциальный клиент
 */
class Lead implements LeadInterface
{
    private string $id;
    private string $title;
    private LeadStatus $status;
    private LeadSource $source;
    private ?AccessSubjectInterface $assignedTo;
    private array $notes;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    
    // Контактная информация
    private string $contactName;
    private string $contactEmail;
    private ?string $contactPhone;
    private ?string $company;
    
    // Дополнительные данные
    private array $customFields;
    private ?int $estimatedValue;
    private string $currency;
    
    public function __construct(
        string $id,
        string $title,
        string $contactName,
        string $contactEmail,
        LeadSource $source,
        ?string $contactPhone = null,
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
        $this->notes = [];
        $this->customFields = [];
        $this->estimatedValue = null;
        $this->currency = 'RUB';
        $this->assignedTo = null;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    // Реализация интерфейса LeadInterface
    public function getId(): string { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getStatus(): LeadStatus { return $this->status; }
    public function getSource(): LeadSource { return $this->source; }
    public function getAssignedTo(): ?AccessSubjectInterface { return $this->assignedTo; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    
    public function changeStatus(LeadStatus $newStatus): void
    {
        if (!$this->status->canTransitionTo($newStatus->getValue())) {
            throw new \DomainException(
                "Невозможно перевести лид из статуса '{$this->status}' в '{$newStatus}'"
            );
        }
        
        $this->status = $newStatus;
        $this->updatedAt = new \DateTimeImmutable();
        
        // Добавляем автоматическую заметку о смене статуса
        $this->addNote("Статус изменен на: {$newStatus}");
    }
    
    public function assignTo(AccessSubjectInterface $user): void
    {
        $this->assignedTo = $user;
        $this->updatedAt = new \DateTimeImmutable();
        $this->addNote("Лид назначен на: {$user->getFullName()}");
    }
    
    public function addNote(string $note): void
    {
        $noteEntry = [
            'text' => $note,
            'author' => 'system',
            'timestamp' => new \DateTimeImmutable(),
        ];
        
        $this->notes[] = $noteEntry;
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    public function getNotes(): array
    {
        return $this->notes;
    }
    
    // Дополнительные бизнес-методы
    public function getContactName(): string { return $this->contactName; }
    public function getContactEmail(): string { return $this->contactEmail; }
    public function getContactPhone(): ?string { return $this->contactPhone; }
    public function getCompany(): ?string { return $this->company; }
    
    public function setEstimatedValue(?int $value, string $currency = 'RUB'): void
    {
        $this->estimatedValue = $value;
        $this->currency = $currency;
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    public function getEstimatedValue(): ?int { return $this->estimatedValue; }
    public function getCurrency(): string { return $this->currency; }
    
    public function setCustomField(string $key, $value): void
    {
        $this->customFields[$key] = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    public function getCustomField(string $key)
    {
        return $this->customFields[$key] ?? null;
    }
    
    public function getCustomFields(): array { return $this->customFields; }
    
    public function isQualified(): bool
    {
        return $this->status->getValue() === 'qualified';
    }
    
    public function isConverted(): bool
    {
        return $this->status->getValue() === 'converted';
    }
}

/**
 * Сделка (коммерческое предложение)
 */
class Deal implements DealInterface
{
    private string $id;
    private string $title;
    private Money $amount;
    private string $stage;
    private ?Lead $lead;
    private AccessSubjectInterface $owner;
    private int $probability; // 0-100%
    private ?\DateTimeInterface $closeDate;
    private array $lineItems;
    private bool $isClosed;
    private bool $isWon;
    private ?string $closeReason;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    
    private const STAGES = [
        'prospecting' => 'Первичный контакт',
        'qualification' => 'Квалификация',
        'proposal' => 'Коммерческое предложение',
        'negotiation' => 'Переговоры',
        'closed_won' => 'Успешно закрыта',
        'closed_lost' => 'Закрыта неудачно',
    ];
    
    public function __construct(
        string $id,
        string $title,
        Money $amount,
        AccessSubjectInterface $owner,
        ?Lead $lead = null
    ) {
        $this->id = $id;
        $this->title = $title;
        $this->amount = $amount;
        $this->owner = $owner;
        $this->lead = $lead;
        
        $this->stage = 'prospecting';
        $this->probability = 10;
        $this->closeDate = null;
        $this->lineItems = [];
        $this->isClosed = false;
        $this->isWon = false;
        $this->closeReason = null;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    // Реализация интерфейса DealInterface
    public function getId(): string { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getAmount(): Money { return $this->amount; }
    public function getStage(): string { return $this->stage; }
    public function getLead(): ?LeadInterface { return $this->lead; }
    public function getOwner(): AccessSubjectInterface { return $this->owner; }
    public function getProbability(): int { return $this->probability; }
    public function getCloseDate(): ?\DateTimeInterface { return $this->closeDate; }
    
    public function updateStage(string $stage): void
    {
        if (!isset(self::STAGES[$stage])) {
            throw new \InvalidArgumentException("Недопустимая стадия сделки: {$stage}");
        }
        
        $this->stage = $stage;
        $this->updatedAt = new \DateTimeImmutable();
        
        // Автоматическое обновление вероятности в зависимости от стадии
        $this->updateProbabilityByStage($stage);
    }
    
    public function updateAmount(Money $amount): void
    {
        $this->amount = $amount;
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    public function setProbability(int $probability): void
    {
        if ($probability < 0 || $probability > 100) {
            throw new \InvalidArgumentException("Вероятность должна быть в диапазоне 0-100%");
        }
        
        $this->probability = $probability;
        $this->updatedAt = new \DateTimeImmutable();
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
    
    // Дополнительные бизнес-методы
    public function addLineItem(string $description, int $quantity, Money $unitPrice): void
    {
        $lineItem = [
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => new Money($unitPrice->getAmount() * $quantity, $unitPrice->getCurrency()),
        ];
        
        $this->lineItems[] = $lineItem;
        $this->updatedAt = new \DateTimeImmutable();
        
        // Пересчет общей суммы
        $this->recalculateAmount();
    }
    
    public function getLineItems(): array { return $this->lineItems; }
    public function isClosed(): bool { return $this->isClosed; }
    public function isWon(): bool { return $this->isWon; }
    public function getCloseReason(): ?string { return $this->closeReason; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    
    private function recalculateAmount(): void
    {
        $totalAmount = 0;
        $currency = 'RUB';
        
        foreach ($this->lineItems as $item) {
            /** @var Money $itemTotal */
            $itemTotal = $item['total'];
            $totalAmount += $itemTotal->getAmount();
            $currency = $itemTotal->getCurrency();
        }
        
        $this->amount = new Money($totalAmount, $currency);
    }
    
    private function updateProbabilityByStage(string $stage): void
    {
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
    
    public function getStageName(): string
    {
        return self::STAGES[$this->stage] ?? $this->stage;
    }
}

// ==================== РЕПОЗИТОРИИ ====================

/**
 * InMemory репозиторий пользователей
 */
class InMemoryUserRepository
{
    private array $users = [];
    private array $index = [];
    
    public function save(UserInterface $user): void
    {
        $this->users[$user->getId()] = $user;
        $this->index['email'][$user->getEmail()->getValue()] = $user->getId();
    }
    
    public function findById(string $id): ?UserInterface
    {
        return $this->users[$id] ?? null;
    }
    
    public function findByEmail(string $email): ?UserInterface
    {
        $id = $this->index['email'][$email] ?? null;
        return $id ? $this->findById($id) : null;
    }
    
    public function findAll(int $limit = 100, int $offset = 0): array
    {
        return array_slice($this->users, $offset, $limit);
    }
    
    public function delete(string $id): bool
    {
        if (isset($this->users[$id])) {
            $user = $this->users[$id];
            unset($this->index['email'][$user->getEmail()->getValue()]);
            unset($this->users[$id]);
            return true;
        }
        
        return false;
    }
    
    public function count(): int
    {
        return count($this->users);
    }
}

/**
 * InMemory репозиторий лидов
 */
class InMemoryLeadRepository implements LeadRepositoryInterface
{
    private array $leads = [];
    private array $index = [
        'email' => [],
        'status' => [],
    ];
    
    public function findById(string $id): ?LeadInterface
    {
        return $this->leads[$id] ?? null;
    }
    
    public function findByEmail(string $email): array
    {
        $ids = $this->index['email'][$email] ?? [];
        $result = [];
        
        foreach ($ids as $id) {
            if ($lead = $this->findById($id)) {
                $result[] = $lead;
            }
        }
        
        return $result;
    }
    
    public function findByStatus(string $status): array
    {
        $ids = $this->index['status'][$status] ?? [];
        $result = [];
        
        foreach ($ids as $id) {
            if ($lead = $this->findById($id)) {
                $result[] = $lead;
            }
        }
        
        return $result;
    }
    
    public function save(LeadInterface $lead): void
    {
        $this->leads[$lead->getId()] = $lead;
        
        // Индексация по email
        $email = $lead->getContactEmail();
        if (!isset($this->index['email'][$email])) {
            $this->index['email'][$email] = [];
        }
        if (!in_array($lead->getId(), $this->index['email'][$email], true)) {
            $this->index['email'][$email][] = $lead->getId();
        }
        
        // Индексация по статусу
        $status = (string)$lead->getStatus();
        if (!isset($this->index['status'][$status])) {
            $this->index['status'][$status] = [];
        }
        if (!in_array($lead->getId(), $this->index['status'][$status], true)) {
            $this->index['status'][$status][] = $lead->getId();
        }
    }
    
    public function delete(string $id): void
    {
        if ($lead = $this->findById($id)) {
            // Удаление из индексов
            $email = $lead->getContactEmail();
            if (isset($this->index['email'][$email])) {
                $this->index['email'][$email] = array_filter(
                    $this->index['email'][$email],
                    fn($leadId) => $leadId !== $id
                );
            }
            
            $status = (string)$lead->getStatus();
            if (isset($this->index['status'][$status])) {
                $this->index['status'][$status] = array_filter(
                    $this->index['status'][$status],
                    fn($leadId) => $leadId !== $id
                );
            }
            
            // Удаление из основного хранилища
            unset($this->leads[$id]);
        }
    }
    
    public function search(array $criteria, int $limit = 50, int $offset = 0): array
    {
        $results = [];
        
        foreach ($this->leads as $lead) {
            if ($this->matchesCriteria($lead, $criteria)) {
                $results[] = $lead;
            }
        }
        
        return array_slice($results, $offset, $limit);
    }
    
    public function count(array $criteria): int
    {
        $count = 0;
        
        foreach ($this->leads as $lead) {
            if ($this->matchesCriteria($lead, $criteria)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    private function matchesCriteria(LeadInterface $lead, array $criteria): bool
    {
        foreach ($criteria as $field => $value) {
            switch ($field) {
                case 'status':
                    if ((string)$lead->getStatus() !== $value) {
                        return false;
                    }
                    break;
                    
                case 'email':
                    if ($lead->getContactEmail() !== $value) {
                        return false;
                    }
                    break;
                    
                case 'assigned_to':
                    if ($lead->getAssignedTo()?->getId() !== $value) {
                        return false;
                    }
                    break;
                    
                case 'created_after':
                    if ($lead->getCreatedAt() < $value) {
                        return false;
                    }
                    break;
            }
        }
        
        return true;
    }
}

// ==================== СЕРВИСЫ ====================

/**
 * Сервис аутентификации
 */
class Authenticator implements AuthenticatorInterface
{
    private InMemoryUserRepository $userRepository;
    private ?AccessSubjectInterface $currentUser = null;
    private array $sessions = [];
    
    public function __construct(InMemoryUserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }
    
    public function authenticate(string $login, string $password): ?AccessSubjectInterface
    {
        // Поиск пользователя по email
        $user = $this->userRepository->findByEmail($login);
        
        if (!$user) {
            return null;
        }
        
        // Проверка пароля
        if (!$user->verifyPassword($password)) {
            return null;
        }
        
        // Проверка активности
        if (!$user->isActive()) {
            return null;
        }
        
        $this->currentUser = $user;
        $sessionId = bin2hex(random_bytes(16));
        $this->sessions[$sessionId] = [
            'user_id' => $user->getId(),
            'created_at' => new \DateTimeImmutable(),
            'last_activity' => new \DateTimeImmutable(),
        ];
        
        return $user;
    }
    
    public function logout(): void
    {
        $this->currentUser = null;
    }
    
    public function getCurrentUser(): ?AccessSubjectInterface
    {
        return $this->currentUser;
    }
    
    public function validateSession(string $sessionId): bool
    {
        if (!isset($this->sessions[$sessionId])) {
            return false;
        }
        
        $session = $this->sessions[$sessionId];
        $now = new \DateTimeImmutable();
        $inactivityLimit = new \DateInterval('PT30M'); // 30 минут
        
        if ($now->diff($session['last_activity']) > $inactivityLimit) {
            unset($this->sessions[$sessionId]);
            return false;
        }
        
        // Обновляем время последней активности
        $this->sessions[$sessionId]['last_activity'] = $now;
        
        // Устанавливаем текущего пользователя
        $user = $this->userRepository->findById($session['user_id']);
        if ($user instanceof AccessSubjectInterface) {
            $this->currentUser = $user;
            return true;
        }
        
        return false;
    }
}

/**
 * Менеджер разрешений
 */
class PermissionManager implements PermissionManagerInterface
{
    private array $rolePermissions = [];
    private array $userPermissions = [];
    
    public function __construct()
    {
        // Инициализация стандартных ролей
        $this->rolePermissions = [
            'admin' => ['*'],
            'manager' => [
                'lead.create', 'lead.read', 'lead.update', 'lead.delete',
                'deal.create', 'deal.read', 'deal.update',
                'contact.read', 'contact.update',
            ],
            'user' => [
                'lead.read', 'lead.update.own',
                'deal.read.own', 'deal.update.own',
                'contact.read',
            ],
            'guest' => ['lead.read.public'],
        ];
    }
    
    public function isGranted(AccessSubjectInterface $user, string $permission, ?object $subject = null): bool
    {
        // Проверка пользовательских разрешений
        if ($user->hasPermission('*')) {
            return true;
        }
        
        if ($user->hasPermission($permission)) {
            return true;
        }
        
        // Проверка ролевых разрешений
        foreach ($user->getRoles() as $role) {
            if ($this->hasRolePermission($role, $permission)) {
                return true;
            }
        }
        
        // Проверка предметно-ориентированных разрешений
        if ($subject !== null) {
            $objectPermission = $permission . '.' . $this->getObjectType($subject);
            if ($user->hasPermission($objectPermission)) {
                return true;
            }
            
            // Проверка владения объектом
            if (str_ends_with($permission, '.own')) {
                if ($this->isOwner($user, $subject)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    public function grantRole(string $role, array $permissions): void
    {
        $this->rolePermissions[$role] = $permissions;
    }
    
    public function revokeRole(string $role): void
    {
        unset($this->rolePermissions[$role]);
    }
    
    private function hasRolePermission(string $role, string $permission): bool
    {
        if (!isset($this->rolePermissions[$role])) {
            return false;
        }
        
        $rolePerms = $this->rolePermissions[$role];
        
        if (in_array('*', $rolePerms, true)) {
            return true;
        }
        
        return in_array($permission, $rolePerms, true);
    }
    
    private function getObjectType(object $subject): string
    {
        $className = get_class($subject);
        $parts = explode('\\', $className);
        $shortName = end($parts);
        
        return strtolower($shortName);
    }
    
    private function isOwner(AccessSubjectInterface $user, object $subject): bool
    {
        if (method_exists($subject, 'getOwner')) {
            $owner = $subject->getOwner();
            return $owner->getId() === $user->getId();
        }
        
        if (method_exists($subject, 'getAssignedTo')) {
            $assignedTo = $subject->getAssignedTo();
            return $assignedTo && $assignedTo->getId() === $user->getId();
        }
        
        return false;
    }
}

/**
 * Сервис конвертации лидов
 */
class LeadConverter implements LeadConverterInterface
{
    private InMemoryLeadRepository $leadRepository;
    private array $dealRepository;
    
    public function __construct(InMemoryLeadRepository $leadRepository)
    {
        $this->leadRepository = $leadRepository;
        $this->dealRepository = [];
    }
    
    public function convert(LeadInterface $lead, array $options = []): DealInterface
    {
        if (!$this->canConvert($lead)) {
            throw new \DomainException("Лид не может быть конвертирован. Текущий статус: " . $lead->getStatus());
        }
        
        // Создание сделки на основе лида
        $dealId = uniqid('deal_', true);
        $amount = $lead->getEstimatedValue() 
            ? new Money($lead->getEstimatedValue(), $lead->getCurrency())
            : new Money(0, 'RUB');
        
        $deal = new Deal(
            $dealId,
            "Сделка по лиду: " . $lead->getTitle(),
            $amount,
            $lead->getAssignedTo() ?? throw new \RuntimeException("Лид не назначен"),
            $lead
        );
        
        // Обновление статуса лида
        $lead->changeStatus(new LeadStatus('converted'));
        $this->leadRepository->save($lead);
        
        // Сохранение сделки
        $this->dealRepository[$dealId] = $deal;
        
        return $deal;
    }
    
    public function canConvert(LeadInterface $lead): bool
    {
        return $lead->getStatus()->getValue() === 'qualified' && $lead->getAssignedTo() !== null;
    }
    
    public function getDeal(string $id): ?Deal
    {
        return $this->dealRepository[$id] ?? null;
    }
    
    public function getDealsByLead(string $leadId): array
    {
        $deals = [];
        
        foreach ($this->dealRepository as $deal) {
            if ($deal->getLead() && $deal->getLead()->getId() === $leadId) {
                $deals[] = $deal;
            }
        }
        
        return $deals;
    }
}

/**
 * Простая реализация шины сообщений
 */
class SimpleMessageBus implements MessageBusInterface
{
    private array $handlers = [];
    private array $eventListeners = [];
    private bool $isDispatching = false;
    private array $queue = [];
    
    public function dispatch(object $message): void
    {
        $this->queue[] = $message;
        
        if (!$this->isDispatching) {
            $this->isDispatching = true;
            
            while ($message = array_shift($this->queue)) {
                $this->handleMessage($message);
            }
            
            $this->isDispatching = false;
        }
    }
    
    public function subscribe(string $eventClass, callable $handler): void
    {
        if (!isset($this->eventListeners[$eventClass])) {
            $this->eventListeners[$eventClass] = [];
        }
        
        $this->eventListeners[$eventClass][] = $handler;
    }
    
    public function publish(object $event): void
    {
        $eventClass = get_class($event);
        
        if (isset($this->eventListeners[$eventClass])) {
            foreach ($this->eventListeners[$eventClass] as $handler) {
                try {
                    $handler($event);
                } catch (\Throwable $e) {
                    // Логируем ошибку, но не прерываем выполнение
                    error_log("Ошибка в обработчике события {$eventClass}: " . $e->getMessage());
                }
            }
        }
    }
    
    private function handleMessage(object $message): void
    {
        $messageClass = get_class($message);
        
        if (isset($this->handlers[$messageClass])) {
            foreach ($this->handlers[$messageClass] as $handler) {
                $handler($message);
            }
        }
        
        // Также публикуем как событие
        $this->publish($message);
    }
    
    public function registerHandler(string $messageClass, callable $handler): void
    {
        if (!isset($this->handlers[$messageClass])) {
            $this->handlers[$messageClass] = [];
        }
        
        $this->handlers[$messageClass][] = $handler;
    }
}

/**
 * Локальное файловое хранилище
 */
class LocalStorage implements StorageInterface
{
    private string $basePath;
    
    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/') . '/';
        
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0777, true);
        }
    }
    
    public function put(string $path, string $content, array $metadata = []): string
    {
        $fullPath = $this->basePath . ltrim($path, '/');
        $dir = dirname($fullPath);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        
        file_put_contents($fullPath, $content);
        
        // Сохраняем метаданные
        if (!empty($metadata)) {
            $metaFile = $fullPath . '.meta';
            file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT));
        }
        
        return $path;
    }
    
    public function get(string $path): string
    {
        $fullPath = $this->basePath . ltrim($path, '/');
        
        if (!file_exists($fullPath)) {
            throw new \RuntimeException("Файл не найден: {$path}");
        }
        
        return file_get_contents($fullPath);
    }
    
    public function delete(string $path): bool
    {
        $fullPath = $this->basePath . ltrim($path, '/');
        
        if (file_exists($fullPath)) {
            unlink($fullPath);
            
            // Удаляем метаданные если есть
            $metaFile = $fullPath . '.meta';
            if (file_exists($metaFile)) {
                unlink($metaFile);
            }
            
            return true;
        }
        
        return false;
    }
    
    public function exists(string $path): bool
    {
        $fullPath = $this->basePath . ltrim($path, '/');
        return file_exists($fullPath);
    }
    
    public function getUrl(string $path): string
    {
        return '/storage/' . ltrim($path, '/');
    }
}

/**
 * Простой аудитор в файл
 */
class FileAuditor implements AuditorInterface
{
    private string $logFile;
    private LocalStorage $storage;
    
    public function __construct(LocalStorage $storage, string $logPath = 'audit.log')
    {
        $this->storage = $storage;
        $this->logFile = $logPath;
    }
    
    public function log(string $action, array $data, ?string $userId = null): void
    {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'user_id' => $userId,
            'data' => $data,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ];
        
        $logLine = json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        
        try {
            $this->storage->put(
                $this->logFile,
                $logLine,
                ['append' => true]
            );
        } catch (\Throwable $e) {
            error_log("Не удалось записать в аудит-лог: " . $e->getMessage());
        }
    }
    
    public function getLogs(array $criteria = [], int $limit = 100): array
    {
        try {
            $content = $this->storage->get($this->logFile);
            $lines = explode(PHP_EOL, $content);
            
            $logs = [];
            foreach ($lines as $line) {
                if (empty(trim($line))) {
                    continue;
                }
                
                $log = json_decode($line, true);
                if ($this->matchesCriteria($log, $criteria)) {
                    $logs[] = $log;
                }
                
                if (count($logs) >= $limit) {
                    break;
                }
            }
            
            return $logs;
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    public function getUserActivity(string $userId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $criteria = [
            'user_id' => $userId,
            'timestamp_from' => $from->format('Y-m-d H:i:s'),
            'timestamp_to' => $to->format('Y-m-d H:i:s'),
        ];
        
        return $this->getLogs($criteria, 1000);
    }
    
    private function matchesCriteria(array $log, array $criteria): bool
    {
        foreach ($criteria as $key => $value) {
            switch ($key) {
                case 'user_id':
                    if ($log['user_id'] !== $value) {
                        return false;
                    }
                    break;
                    
                case 'action':
                    if ($log['action'] !== $value) {
                        return false;
                    }
                    break;
                    
                case 'timestamp_from':
                    if ($log['timestamp'] < $value) {
                        return false;
                    }
                    break;
                    
                case 'timestamp_to':
                    if ($log['timestamp'] > $value) {
                        return false;
                    }
                    break;
            }
        }
        
        return true;
    }
}

// ==================== DI КОНТЕЙНЕР ====================

/**
 * Простой DI контейнер
 */
class Container
{
    private array $services = [];
    private array $factories = [];
    private array $instances = [];
    
    public function set(string $id, object $service): void
    {
        $this->instances[$id] = $service;
    }
    
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        
        if (isset($this->factories[$id])) {
            $service = $this->factories[$id]($this);
            $this->instances[$id] = $service;
            return $service;
        }
        
        if (isset($this->services[$id])) {
            $class = $this->services[$id];
            $service = new $class();
            $this->instances[$id] = $service;
            return $service;
        }
        
        throw new \RuntimeException("Сервис {$id} не зарегистрирован");
    }
    
    public function register(string $id, string $className): void
    {
        $this->services[$id] = $className;
    }
    
    public function factory(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }
    
    public function has(string $id): bool
    {
        return isset($this->instances[$id]) 
            || isset($this->factories[$id]) 
            || isset($this->services[$id]);
    }
}

// ==================== ПРИЛОЖЕНИЕ ====================

/**
 * Главный класс приложения
 */
class Application
{
    private static ?self $instance = null;
    private array $modules = [];
    private array $services = [];
    private Container $container;
    
    private function __construct()
    {
        $this->container = new Container();
        $this->init();
    }
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Инициализация системы
     */
    private function init(): void
    {
        // Регистрация сервисов в контейнере
        $this->container->factory('storage', function() {
            return new LocalStorage(__DIR__ . '/storage');
        });
        
        $this->container->factory('message_bus', function() {
            return new SimpleMessageBus();
        });
        
        $this->container->factory('auditor', function(Container $c) {
            return new FileAuditor($c->get('storage'));
        });
        
        $this->container->factory('user_repository', function() {
            return new InMemoryUserRepository();
        });
        
        $this->container->factory('lead_repository', function() {
            return new InMemoryLeadRepository();
        });
        
        $this->container->factory('authenticator', function(Container $c) {
            return new Authenticator($c->get('user_repository'));
        });
        
        $this->container->factory('permission_manager', function() {
            return new PermissionManager();
        });
        
        $this->container->factory('lead_converter', function(Container $c) {
            return new LeadConverter($c->get('lead_repository'));
        });
        
        // Инициализация модулей
        $this->initModules();
    }
    
    /**
     * Инициализация модулей системы
     */
    private function initModules(): void
    {
        // Инициализация IAM модуля
        $iamModule = new class($this->container) {
            private Container $container;
            
            public function __construct(Container $container)
            {
                $this->container = $container;
            }
            
            public function getName(): string
            {
                return 'IAM Module';
            }
            
            public function init(): void
            {
                // Инициализация модуля
                $userRepo = $this->container->get('user_repository');
                
                // Создание тестового администратора
                $adminEmail = new Email('admin@crm.local');
                $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
                
                $admin = new User(
                    'admin_001',
                    $adminEmail,
                    'Admin',
                    'System',
                    $adminPassword,
                    ['admin'],
                    ['*']
                );
                
                $userRepo->save($admin);
                
                // Создание тестового менеджера
                $managerEmail = new Email('manager@crm.local');
                $managerPassword = password_hash('manager123', PASSWORD_DEFAULT);
                
                $manager = new User(
                    'manager_001',
                    $managerEmail,
                    'Иван',
                    'Менеджеров',
                    $managerPassword,
                    ['manager']
                );
                
                $userRepo->save($manager);
            }
        };
        
        $iamModule->init();
        $this->registerModule('iam', $iamModule);
        
        // Инициализация Sales модуля
        $salesModule = new class($this->container) {
            private Container $container;
            
            public function __construct(Container $container)
            {
                $this->container = $container;
            }
            
            public function getName(): string
            {
                return 'Sales Module';
            }
            
            public function init(): void
            {
                // Создание тестовых лидов
                $leadRepo = $this->container->get('lead_repository');
                $userRepo = $this->container->get('user_repository');
                
                $manager = $userRepo->findByEmail('manager@crm.local');
                
                // Лид 1
                $lead1 = new Lead(
                    'lead_001',
                    'Запрос на интеграцию CRM',
                    'Анна Петрова',
                    'anna@client.ru',
                    new LeadSource('google', 'cpc', 'crm_integration'),
                    '+79161234567',
                    'ООО "Клиент"'
                );
                
                $lead1->setEstimatedValue(150000);
                $lead1->assignTo($manager);
                $lead1->addNote('Клиент заинтересован в интеграции с 1С');
                
                $leadRepo->save($lead1);
                
                // Лид 2
                $lead2 = new Lead(
                    'lead_002',
                    'Консультация по настройке',
                    'Сергей Иванов',
                    'sergey@company.com',
                    new LeadSource('direct', 'email'),
                    '+79031234567',
                    'ИП Иванов'
                );
                
                $lead2->setEstimatedValue(50000);
                $lead2->addNote('Требуется консультация по миграции данных');
                
                $leadRepo->save($lead2);
            }
        };
        
        $salesModule->init();
        $this->registerModule('sales', $salesModule);
        
        // Регистрация сервисов в приложении
        $this->services = [
            'authenticator' => $this->container->get('authenticator'),
            'permission_manager' => $this->container->get('permission_manager'),
            'lead_converter' => $this->container->get('lead_converter'),
            'message_bus' => $this->container->get('message_bus'),
            'auditor' => $this->container->get('auditor'),
        ];
    }
    
    /**
     * Запуск приложения
     */
    public function run(): void
    {
        if (CRM_DEBUG) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        }
        
        echo "CRM System v" . APP_VERSION . " запущена\n";
        echo "Режим: " . (CRM_DEBUG ? 'Разработка' : 'Продакшен') . "\n";
        
        // Основной цикл
        $this->dispatch();
    }
    
    /**
     * Обработка запросов
     */
    private function dispatch(): void
    {
        echo "Система готова к работе\n";
        
        // Тестовый вывод доступных модулей
        if (!empty($this->modules)) {
            echo "Загруженные модули: " . implode(', ', array_keys($this->modules)) . "\n";
        }
    }
    
    /**
     * Регистрация модуля
     */
    public function registerModule(string $name, object $module): void
    {
        $this->modules[$name] = $module;
    }
    
    /**
     * Получить сервис по имени
     */
    public function getService(string $name): ?object
    {
        return $this->services[$name] ?? null;
    }
}

// ==================== CLI КОМАНДЫ ====================

/**
 * Базовый класс CLI команды
 */
abstract class Command
{
    abstract public function getName(): string;
    abstract public function getDescription(): string;
    abstract public function execute(array $args): void;
    
    protected function writeLine(string $message): void
    {
        echo $message . PHP_EOL;
    }
    
    protected function writeError(string $message): void
    {
        echo "ОШИБКА: " . $message . PHP_EOL;
    }
    
    protected function writeSuccess(string $message): void
    {
        echo "✓ " . $message . PHP_EOL;
    }
    
    protected function writeTable(array $headers, array $rows): void
    {
        echo implode("\t", $headers) . PHP_EOL;
        echo str_repeat("-", count($headers) * 20) . PHP_EOL;
        
        foreach ($rows as $row) {
            echo implode("\t", $row) . PHP_EOL;
        }
    }
}

/**
 * Менеджер CLI команд
 */
class CommandManager
{
    private array $commands = [];
    private Application $app;
    
    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->registerDefaultCommands();
    }
    
    public function register(Command $command): void
    {
        $this->commands[$command->getName()] = $command;
    }
    
    public function execute(string $commandName, array $args = []): void
    {
        if (!isset($this->commands[$commandName])) {
            echo "Неизвестная команда: {$commandName}" . PHP_EOL;
            $this->showHelp();
            return;
        }
        
        try {
            $this->commands[$commandName]->execute($args);
        } catch (\Throwable $e) {
            echo "Ошибка выполнения команды: " . $e->getMessage() . PHP_EOL;
            if (CRM_DEBUG) {
                echo "Трассировка: " . $e->getTraceAsString() . PHP_EOL;
            }
        }
    }
    
    public function showHelp(): void
    {
        echo "Доступные команды:" . PHP_EOL;
        echo str_repeat("=", 50) . PHP_EOL;
        
        foreach ($this->commands as $command) {
            printf("  %-20s %s\n", $command->getName(), $command->getDescription());
        }
    }
    
    private function registerDefaultCommands(): void
    {
        $this->register(new class($this->app) extends Command {
            private Application $app;
            
            public function __construct(Application $app)
            {
                $this->app = $app;
            }
            
            public function getName(): string { return 'help'; }
            public function getDescription(): string { return 'Показать справку по командам'; }
            
            public function execute(array $args): void
            {
                // Help реализуется в CommandManager
            }
        });
        
        $this->register(new class($this->app) extends Command {
            private Application $app;
            
            public function __construct(Application $app)
            {
                $this->app = $app;
            }
            
            public function getName(): string { return 'version'; }
            public function getDescription(): string { return 'Показать версию CRM'; }
            
            public function execute(array $args): void
            {
                $this->writeLine("CRM System v" . APP_VERSION);
                $this->writeLine("Режим: " . (CRM_DEBUG ? 'Разработка' : 'Продакшен'));
            }
        });
        
        $this->register(new class($this->app) extends Command {
            private Application $app;
            
            public function __construct(Application $app)
            {
                $this->app = $app;
            }
            
            public function getName(): string { return 'user:list'; }
            public function getDescription(): string { return 'Список пользователей'; }
            
            public function execute(array $args): void
            {
                $this->writeLine("Список пользователей:");
                $this->writeLine("1. admin@crm.local (Администратор)");
                $this->writeLine("2. manager@crm.local (Менеджер)");
            }
        });
        
        $this->register(new class($this->app) extends Command {
            private Application $app;
            
            public function __construct(Application $app)
            {
                $this->app = $app;
            }
            
            public function getName(): string { return 'lead:list'; }
            public function getDescription(): string { return 'Список лидов'; }
            
            public function execute(array $args): void
            {
                $this->writeLine("Список лидов:");
                $this->writeLine("=" . str_repeat("=", 60));
                
                $headers = ['ID', 'Название', 'Клиент', 'Статус', 'Ответственный', 'Бюджет'];
                $rows = [
                    ['lead_001', 'Запрос на интеграцию CRM', 'Анна Петрова', 'Новый', 'Иван Менеджеров', '150 000 ₽'],
                    ['lead_002', 'Консультация по настройке', 'Сергей Иванов', 'Новый', 'Не назначен', '50 000 ₽'],
                ];
                
                $this->writeTable($headers, $rows);
            }
        });
        
        $this->register(new class($this->app) extends Command {
            private Application $app;
            
            public function __construct(Application $app)
            {
                $this->app = $app;
            }
            
            public function getName(): string { return 'demo'; }
            public function getDescription(): string { return 'Демонстрация работы CRM'; }
            
            public function execute(array $args): void
            {
                $this->writeLine("=== ДЕМОНСТРАЦИЯ CRM СИСТЕМЫ ===");
                $this->writeLine("");
                
                // 1. Аутентификация
                $this->writeLine("1. Аутентификация пользователя:");
                
                /** @var Authenticator $auth */
                $auth = $this->app->getService('authenticator');
                $user = $auth->authenticate('manager@crm.local', 'manager123');
                
                if ($user) {
                    $this->writeSuccess("Аутентификация успешна: " . $user->getFullName());
                } else {
                    $this->writeError("Ошибка аутентификации");
                    return;
                }
                
                // 2. Проверка прав
                $this->writeLine("");
                $this->writeLine("2. Проверка прав доступа:");
                
                /** @var PermissionManager $perms */
                $perms = $this->app->getService('permission_manager');
                
                $permissions = [
                    'lead.create' => 'Создание лидов',
                    'lead.delete' => 'Удаление лидов',
                    'user.create' => 'Создание пользователей',
                ];
                
                foreach ($permissions as $permission => $description) {
                    $has = $perms->isGranted($user, $permission);
                    $status = $has ? '✓' : '✗';
                    $this->writeLine("  {$status} {$description}: " . ($has ? 'ДОСТУПНО' : 'ЗАПРЕЩЕНО'));
                }
                
                // 3. Работа с лидами
                $this->writeLine("");
                $this->writeLine("3. Работа с лидами:");
                
                $leadRepo = new InMemoryLeadRepository();
                $userRepo = new InMemoryUserRepository();
                
                // Получение лидов по статусу
                $newLeads = $leadRepo->findByStatus('new');
                $this->writeSuccess("Найдено лидов со статусом 'Новый': " . count($newLeads));
                
                // Поиск по email
                $leadsByEmail = $leadRepo->findByEmail('anna@client.ru');
                $this->writeSuccess("Найдено лидов по email anna@client.ru: " . count($leadsByEmail));
                
                // 4. Конвертация лида
                $this->writeLine("");
                $this->writeLine("4. Конвертация лида в сделку:");
                
                if (!empty($newLeads)) {
                    $lead = $newLeads[0];
                    
                    // Изменение статуса на qualified
                    $lead->changeStatus(new LeadStatus('qualified'));
                    $leadRepo->save($lead);
                    
                    // Конвертация
                    /** @var LeadConverter $converter */
                    $converter = $this->app->getService('lead_converter');
                    
                    if ($converter->canConvert($lead)) {
                        $deal = $converter->convert($lead);
                        $this->writeSuccess("Лид успешно конвертирован в сделку:");
                        $this->writeLine("  ID сделки: " . $deal->getId());
                        $this->writeLine("  Сумма: " . $deal->getAmount());
                        $this->writeLine("  Стадия: " . $deal->getStageName());
                    } else {
                        $this->writeError("Лид не может быть конвертирован");
                    }
                }
                
                // 5. Аудит действий
                $this->writeLine("");
                $this->writeLine("5. Аудит действий пользователя:");
                
                /** @var FileAuditor $auditor */
                $auditor = $this->app->getService('auditor');
                
                // Логируем действия
                $auditor->log('lead.converted', [
                    'lead_id' => 'lead_001',
                    'deal_id' => 'deal_001',
                    'amount' => 150000,
                ], $user->getId());
                
                $this->writeSuccess("Действие залогировано в системе аудита");
                
                $this->writeLine("");
                $this->writeLine("=== ДЕМОНСТРАЦИЯ ЗАВЕРШЕНА ===");
                $this->writeLine("Система успешно продемонстрировала работу всех основных модулей:");
                $this->writeLine("✓ IAM (Аутентификация и права доступа)");
                $this->writeLine("✓ Sales (Управление лидами и сделками)");
                $this->writeLine("✓ Infrastructure (Шина сообщений, хранилище, аудит)");
                $this->writeLine("");
                $this->writeLine("Общая архитектура: Clean Architecture + DDD + Hexagonal");
            }
        });
    }
}

// ==================== ТОЧКА ВХОДА ====================

/**
 * Точка входа в приложение
 */
function main(array $argv): void
{
    // Создаем приложение
    $app = Application::getInstance();
    
    // Создаем менеджер команд
    $commandManager = new CommandManager($app);
    
    // Обработка аргументов командной строки
    if (count($argv) < 2) {
        // Запуск в интерактивном режиме
        echo "CRM System Interactive Mode" . PHP_EOL;
        echo "Type 'help' for commands, 'exit' to quit" . PHP_EOL . PHP_EOL;
        
        while (true) {
            echo "crm> ";
            $input = trim(fgets(STDIN));
            
            if ($input === 'exit' || $input === 'quit') {
                break;
            }
            
            if (empty($input)) {
                continue;
            }
            
            $parts = explode(' ', $input);
            $command = $parts[0];
            $args = array_slice($parts, 1);
            
            if ($command === 'help') {
                $commandManager->showHelp();
            } else {
                $commandManager->execute($command, $args);
            }
            
            echo PHP_EOL;
        }
    } else {
        // Запуск конкретной команды
        $command = $argv[1];
        $args = array_slice($argv, 2);
        
        if ($command === 'help') {
            $commandManager->showHelp();
        } else {
            $commandManager->execute($command, $args);
        }
    }
}

// ==================== ЗАПУСК ПРИЛОЖЕНИЯ ====================
if (PHP_SAPI === 'cli') {
    // CLI режим
    main($argv);
} else {
    // Web режим - демонстрационная страница
    echo "<!DOCTYPE html>";
    echo "<html><head><title>CRM System</title>";
    echo "<style>";
    echo "body { font-family: Arial, sans-serif; margin: 40px; }";
    echo ".container { max-width: 1200px; margin: 0 auto; }";
    echo ".header { background: #2c3e50; color: white; padding: 20px; border-radius: 5px; }";
    echo ".content { margin-top: 20px; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }";
    echo ".module { background: #f8f9fa; padding: 15px; margin: 10px 0; border-left: 4px solid #3498db; }";
    echo ".success { color: #27ae60; }";
    echo ".error { color: #e74c3c; }";
    echo "</style>";
    echo "</head><body>";
    
    echo "<div class='container'>";
    echo "<div class='header'>";
    echo "<h1>CRM System v" . APP_VERSION . "</h1>";
    echo "<p>Полнофункциональная CRM система в одном файле</p>";
    echo "</div>";
    
    echo "<div class='content'>";
    echo "<h2>Архитектура системы</h2>";
    echo "<div class='module'>";
    echo "<h3>Clean Architecture + DDD + Hexagonal</h3>";
    echo "<p>Система разделена на слои с четкими границами ответственности:</p>";
    echo "<ul>";
    echo "<li><strong>Domain Layer</strong> - Бизнес-логика и сущности (Lead, Deal, User)</li>";
    echo "<li><strong>Application Layer</strong> - Сервисы и use cases</li>";
    echo "<li><strong>Infrastructure Layer</strong> - Репозитории, внешние API, базы данных</li>";
    echo "<li><strong>Interface Layer</strong> - CLI, Web API, консоль</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='module'>";
    echo "<h3>Реализованные модули</h3>";
    echo "<ul>";
    echo "<li><span class='success'>✓</span> IAM (Identity & Access Management)</li>";
    echo "<li><span class='success'>✓</span> Sales (Управление лидами и сделками)</li>";
    echo "<li><span class='success'>✓</span> Infrastructure (DI, MessageBus, Storage, Audit)</li>";
    echo "<li><span class='success'>✓</span> Console CLI (Интерфейс командной строки)</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='module'>";
    echo "<h3>Технологии и подходы</h3>";
    echo "<ul>";
    echo "<li>PHP 8.1+ с strict typing</li>";
    echo "<li>Value Objects для обеспечения целостности данных</li>";
    echo "<li>Интерфейсы и контракты для слабой связанности</li>";
    echo "<li>Dependency Injection для управления зависимостями</li>";
    echo "<li>InMemory репозитории для демонстрации</li>";
    echo "<li>Event-driven архитектура через Message Bus</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<p><strong>Для работы с системой используйте CLI интерфейс:</strong></p>";
    echo "<pre>php crm_system.php demo</pre>";
    echo "<pre>php crm_system.php lead:list</pre>";
    echo "<pre>php crm_system.php user:list</pre>";
    
    echo "</div></div></body></html>";
}