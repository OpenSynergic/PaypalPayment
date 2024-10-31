<?php

namespace PaypalPayment;

use App\Classes\Plugin;
use App\Facades\Hook;
use App\Infolists\Components\LivewireEntry;
use App\Infolists\Components\VerticalTabs as InfolistsVerticalTabs;
use Filament\Panel;
use PaypalPayment\Frontend\ScheduledConference\Pages\PaypalPage;
use PaypalPayment\Panel\ScheduledConference\Livewire\PaypalSetting;
use Rahmanramsi\LivewirePageGroup\PageGroup;

class PaypalPaymentPlugin extends Plugin
{
	public function boot() {}

	public function onFrontend(PageGroup $frontend): void
	{
		if ($frontend->getId() !== 'scheduledConference') {
			return;
		}

		$frontend->discoverPages(in: $this->pluginPath . '/src/Frontend/ScheduledConference/Pages', for: 'PaypalPayment\\Frontend\\ScheduledConference\\Pages');
	}

	public function onPanel(Panel $panel): void
	{
		if ($panel->getId() !== 'scheduledConference') {
			return;
		}

		$panel->discoverLivewireComponents(in: $this->pluginPath . '/src/Panel/ScheduledConference/Livewire', for: 'PaypalPayment\\Panel\\ScheduledConference\\Livewire');

		Hook::add('Payments::PaymentTab', function ($hookName, &$tabs) {
			$tabs[] = InfolistsVerticalTabs\Tab::make('paypal')
				->label('Paypal')
				->icon('heroicon-o-credit-card')
				->schema([
					LivewireEntry::make('settings')
						->livewire(PaypalSetting::class)
				]);
		});

		if ($this->isProperlySetup()) {
			Hook::add('ParticipantRegisterStatus::PaymentDetails', function ($hookName, $participantRegisterStatus, $userRegistration, &$paymentDetails) {
				$paymentDetails['Paypal'] = view('PaypalPayment::paypal-button', [
					'url' => route(PaypalPage::getRouteName('scheduledConference'), ['id' => $userRegistration->id]),
				]);
			});
		}
	}

	public function isProperlySetup(): bool
	{
		return $this->getClientId() && $this->getClientSecret();
	}

	public function isTestMode(): bool
	{
		return $this->getSetting('test_mode', false);
	}

	public function getClientId(): ?string
	{
		return $this->isTestMode() ? $this->getSetting('client_id_test') : $this->getSetting('client_id');
	}

	public function getClientSecret(): ?string
	{
		return $this->isTestMode() ? $this->getSetting('client_secret_test') : $this->getSetting('client_secret');
	}
}
