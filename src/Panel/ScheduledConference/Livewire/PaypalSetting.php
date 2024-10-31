<?php

namespace PaypalPayment\Panel\ScheduledConference\Livewire;

use Livewire\Component;
use Filament\Forms\Form;
use Filament\Forms\Components\Actions;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use App\Facades\Plugin;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;

class PaypalSetting extends Component implements HasForms
{
	use InteractsWithForms;

	public ?array $formData = [];

	public function mount(): void
	{
		$paypalPlugin = Plugin::getPlugin('PaypalPayment');

		$this->form->fill([
			'test_mode' => $paypalPlugin->getSetting('test_mode', false),
			'client_id' => $paypalPlugin->getSetting('client_id', ''),
			'client_secret' => $paypalPlugin->getSetting('client_secret', ''),
			'client_id_test' => $paypalPlugin->getSetting('client_id_test', ''),
			'client_secret_test' => $paypalPlugin->getSetting('client_secret_test', ''),
		]);
	}

	public function form(Form $form): Form
	{
		return $form
			->schema([
				Section::make()
					->schema([
						Checkbox::make('test_mode')
							->label('Sandbox')
							->reactive()
							->extraAttributes([
								'x-on:change' => 'console.log($wire.formData.test_mode)',
							])
							->helperText('Enable sandbox mode for testing'),
						Grid::make(1)
							->maxWidth('xl')
							->hidden(fn(Get $get) => $get('test_mode'))
							->schema([
								TextInput::make('client_id')
									->label('Live Client ID'),
								TextInput::make('client_secret')
									->label('Live Client Secret'),
							]),
						Grid::make(1)
							->maxWidth('xl')
							->visible(fn(Get $get) => $get('test_mode'))
							->schema([
								TextInput::make('client_id_test')
									->label('Sandbox Client ID'),
								TextInput::make('client_secret_test')
									->label('Sandbox Client Secret'),
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
								$paypalPlugin->updateSetting('test_mode', $formData['test_mode']);
								if (!$formData['test_mode']) {
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
						->authorize('RegistrationSetting:update'),
				]),
			])
			->statePath('formData');
	}

	public function render()
	{
		return view('forms.form');
	}
}
