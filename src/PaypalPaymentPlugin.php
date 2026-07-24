<?php

namespace PaypalPayment;

use App\Classes\Plugin;
use App\Facades\Hook;
use App\Infolists\Components\VerticalTabs as InfolistsVerticalTabs;
use Awcodes\Shout\Components\ShoutEntry;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Panel;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use PaypalPayment\Panel\ScheduledConference\Livewire\PaypalSetting;
use PaypalPayment\Panel\ScheduledConference\Pages\PaypalPage;

class PaypalPaymentPlugin extends Plugin
{
    public function boot()
    {
        if (! app()->getCurrentScheduledConference()) {
            return;
        }
        if ($this->isProperlySetup()) {
            Hook::add('PaymentManager::getPaymentMethodActions', function ($hookName, &$actions) {
                $actions['paypal'] = Action::make('paypal')
                    ->label('Paypal Payment')
                    ->url(fn ($record) => route(PaypalPage::getRouteName('scheduledConference'), ['id' => $record->getKey()]));

                return false;
            });

            Hook::add('PaymentManager::getPaymentMethodInfolist', function ($hookName, &$schemas) {
                $schemas[] = Section::make('Paypal Payment')
                    ->visible(fn ($record) => $record->payment_method == 'paypal')
                    ->description('')
                    ->schema([
                        ShoutEntry::make('information')
                            ->content('Detailed financial information is securely stored on PayPal')
                            ->type('info'),
                        TextEntry::make('payment_id')
                            ->label('Payment ID')
                            ->getStateUsing(fn ($record) => $record->getMeta('paypal_payment_id')),
                        TextEntry::make('paypal_token')
                            ->label('Token')
                            ->visible(fn () => auth()->user()?->can('update', app()->getCurrentScheduledConference()))
                            ->getStateUsing(fn ($record) => $record->getMeta('paypal_token')),
                        TextEntry::make('paypal_payer_id')
                            ->label('Payer ID')
                            ->visible(fn () => auth()->user()?->can('update', app()->getCurrentScheduledConference()))
                            ->getStateUsing(fn ($record) => $record->getMeta('paypal_payer_id')),
                    ]);

                return false;
            });
        }
    }

    public function onPanel(Panel $panel): void
    {
        if ($panel->getId() !== 'scheduledConference') {
            return;
        }

        $panel->discoverLivewireComponents(in: $this->pluginPath.'/src/Panel/ScheduledConference/Livewire', for: 'PaypalPayment\\Panel\\ScheduledConference\\Livewire');

        $panel->pages([
            PaypalPage::class,
        ]);

        Hook::add('Payments::PaymentMethodTabs', function ($hookName, &$tabs) {
            $tabs[] = InfolistsVerticalTabs\Tab::make('paypal')
                ->label('Paypal')
                ->icon('heroicon-o-credit-card')
                ->schema([
                    Livewire::make(PaypalSetting::class),
                ]);
        });
    }

    public function isProperlySetup(): bool
    {
        return $this->getSetting('payment_enabled', false) && $this->getClientId() && $this->getClientSecret();
    }

    public function isTestMode(): bool
    {
        return (bool) $this->getSetting('test_mode', false);
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
