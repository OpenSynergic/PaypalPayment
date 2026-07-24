<?php

namespace PaypalPayment\Panel\ScheduledConference\Livewire;

use App\Facades\Plugin;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Livewire\Component;

class PaypalSetting extends Component implements HasForms, HasActions
{
    use InteractsWithActions;
    use InteractsWithForms;

    public ?array $formData = [];

    public function mount(): void
    {
        $paypalPlugin = Plugin::getPlugin('PaypalPayment');

        $this->form->fill([
            'payment_enabled' => $paypalPlugin->getSetting('payment_enabled', false),
            'test_mode' => $paypalPlugin->getSetting('test_mode', false),
            'client_id' => $paypalPlugin->getSetting('client_id', ''),
            'client_secret' => $paypalPlugin->getSetting('client_secret', ''),
            'client_id_test' => $paypalPlugin->getSetting('client_id_test', ''),
            'client_secret_test' => $paypalPlugin->getSetting('client_secret_test', ''),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Toggle::make('payment_enabled')
                            ->label(__('general.enabled')),
                        Checkbox::make('test_mode')
                            ->label('Sandbox')
                            ->live()
                            ->helperText('Enable sandbox mode for testing'),
                        Grid::make(1)
                            ->maxWidth('xl')
                            ->hidden(fn (Get $get) => (bool) $get('test_mode'))
                            ->schema([
                                TextInput::make('client_id')
                                    ->label('Live Client ID'),
                                TextInput::make('client_secret')
                                    ->label('Live Client Secret')
                                    ->password()
                                    ->revealable(),
                            ]),
                        Grid::make(1)
                            ->maxWidth('xl')
                            ->visible(fn (Get $get) => (bool) $get('test_mode'))
                            ->schema([
                                TextInput::make('client_id_test')
                                    ->label('Sandbox Client ID'),
                                TextInput::make('client_secret_test')
                                    ->label('Sandbox Client Secret')
                                    ->password()
                                    ->revealable(),
                            ]),
                    ]),
                Actions::make([
                    Action::make('save_changes')
                        ->label(__('general.save_changes'))
                        ->successNotificationTitle(__('general.saved'))
                        ->failureNotificationTitle(__('general.data_could_not_saved'))
                        ->action(function (Action $action) {
                            $formData = $this->form->getState();

                            try {
                                $paypalPlugin = Plugin::getPlugin('PaypalPayment');
                                $paypalPlugin->updateSetting('payment_enabled', $formData['payment_enabled']);
                                $paypalPlugin->updateSetting('test_mode', $formData['test_mode']);
                                if (! $formData['test_mode']) {
                                    $paypalPlugin->updateSetting('client_id', $formData['client_id']);
                                    $paypalPlugin->updateSetting('client_secret', $formData['client_secret']);
                                } else {
                                    $paypalPlugin->updateSetting('client_id_test', $formData['client_id_test']);
                                    $paypalPlugin->updateSetting('client_secret_test', $formData['client_secret_test']);
                                }
                            } catch (\Throwable $th) {
                                $action->failure();
                                throw $th;
                            }

                            $action->success();
                        })
                        ->authorize(fn () => auth()->user()?->can('update', app()->getCurrentScheduledConference())),
                ]),
            ])
            ->statePath('formData');
    }

    public function render()
    {
        return view('forms.form');
    }
}
