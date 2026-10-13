<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Dashboard;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetItems;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetButton;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCA\Deferendum\Service\DataService;
use OCA\Deferendum\Service\ConnectionService;
final class DecisionWidget implements IAPIWidgetV2, IButtonWidget, IIconWidget, IReloadableWidget {
    public function __construct(private IL10N $l10n, private IURLGenerator $urls, private DataService $data, private ConnectionService $connections) {}
    public function getId(): string { return 'deferendum'; }
    public function getTitle(): string { return $this->l10n->t('Deferendum'); }
    public function getOrder(): int { return 80; }
    public function getIconClass(): string { return 'icon-deferendum'; }
    public function getIconUrl(): string { return $this->urls->getAbsoluteURL($this->urls->imagePath('deferendum', 'app.svg')); }
    public function getUrl(): ?string { return $this->urls->linkToRouteAbsolute('deferendum.page.index'); }
    public function load(): void {}
    public function getReloadInterval(): int { return 120; }
    public function getWidgetButtons(string $userId): array { return [new WidgetButton(WidgetButton::TYPE_MORE, $this->getUrl(), $this->l10n->t('Open Deferendum'))]; }
    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        try {
            if (!$this->connections->status($userId)['connected']) { return new WidgetItems([], $this->l10n->t('Connect your own Deferendum account')); }
            $limit = max(1, min(7, $limit)); $items = []; $language = $this->l10n->getLanguageCode();
            // Bounded overview. Full pagination is available on the app page, never claimed as exhaustive here.
            $groups = $this->data->groups($userId, $language, 0, 7);
            foreach ($groups['items'] as $group) {
                $topics = $this->data->topics($userId, $group['id'], $language, 'decision', 'active', 0, 7);
                foreach ($topics['items'] as $topic) {
                    $subtitle = $group['name'] . ' · ' . $this->l10n->t($topic['phase']);
                    if ($topic['deadline'] !== null) { $formatter = new \IntlDateFormatter($language, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, 'UTC'); $subtitle .= ' · ' . $formatter->format(strtotime($topic['deadline'])) . ' UTC'; }
                    if ($topic['phase'] === 'closed') {
                        try { $next = $this->data->nextStep($userId, $group['id'], $topic['id'], $language); $subtitle .= ' · ' . $next['nextStep']; }
                        catch (\OCA\Deferendum\Service\ConnectorException $e) { if ($e->reason !== 'not_found') { throw $e; } }
                    }
                    $items[] = new WidgetItem($topic['title'], $subtitle, $topic['url'], $this->getIconUrl(), $group['id'] . ':' . $topic['id']);
                    if (count($items) >= $limit) { break 2; }
                }
            }
            return new WidgetItems($items, $this->l10n->t('No active decisions in this overview'), $this->l10n->t('Overview only. Open the app for all selected groups and pages.'));
        } catch (\Throwable) { return new WidgetItems([], $this->l10n->t('Data unavailable. Previous content is not shown.')); }
    }
}
