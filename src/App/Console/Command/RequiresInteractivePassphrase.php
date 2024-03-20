<?php


namespace Datto\App\Console\Command;

use Datto\App\Console\InteractiveInputRequiredException;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Asset;
use Datto\Asset\AssetType;
use Datto\Utility\Security\SecretString;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Trait RequiresInteractivePassphrase collects functionality needed to prompt for
 * passphrases interactively and unseal/decrypt them if required.
 * @package Datto\App\Console\Command
 */
trait RequiresInteractivePassphrase
{
    /**
     * If the agent is encrypted and CryptTempAccess is not active, prompt for password and unseal the agent.
     *
     * @param Agent $agent
     * @param EncryptionService $encryptionService
     * @param TempAccessService $tempAccessService
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param bool $skipPromptWhenNonInteractive if true, allow skipping of password prompt when the agent is unsealed
     */
    protected function unsealAgentIfRequired(
        Agent $agent,
        EncryptionService $encryptionService,
        TempAccessService $tempAccessService,
        InputInterface $input,
        OutputInterface $output,
        bool $skipPromptWhenNonInteractive = false
    ): void {
        if ($agent->getEncryption()->isEnabled()
            && !$tempAccessService->isCryptTempAccessEnabled($agent->getKeyName())) {
            $isInteractive = $input->isInteractive();
            $isSealed = !$encryptionService->isAgentMasterKeyLoaded($agent->getKeyName());

            if ($isInteractive) {
                // always prompt for passphrase and unseal when we are able
                $passphrase = new SecretString($this->promptPassphrase($input, $output));
                $encryptionService->decryptAgentKey($agent->getKeyName(), $passphrase);
            } elseif ($isSealed || !$skipPromptWhenNonInteractive) {
                throw new InteractiveInputRequiredException();
            }
        }
    }

    /**
     * If agent is encrypted and CryptTempAccess is not active, prompt for the password
     *
     * @param Asset $asset
     * @param TempAccessService $tempAccessService
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return SecretString
     */
    protected function promptAgentPassphraseIfRequired(
        Asset $asset,
        TempAccessService $tempAccessService,
        InputInterface $input,
        OutputInterface $output
    ): SecretString {
        $passphrase = '';

        if (!AssetType::isShare($asset->getType())) {
            /** @var Agent $asset */
            if ($asset->getEncryption()->isEnabled()
                && !$tempAccessService->isCryptTempAccessEnabled($asset->getKeyName())) {
                $passphrase = $this->promptPassphrase($input, $output);
            }
        }

        return new SecretString($passphrase);
    }

    /**
     * Prompt for password input
     *
     * @param string $prompt
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return string
     */
    protected function promptPassphrase(
        InputInterface $input,
        OutputInterface $output,
        string $prompt = 'Passphrase: '
    ): string {
        if (!$input->isInteractive()) {
            throw new InteractiveInputRequiredException();
        }

        $passphraseQuestion = new Question($prompt);
        $passphraseQuestion->setHidden(true);
        $passphraseQuestion->setHiddenFallback(false);

        $questionHelper = $this->getHelper('question');
        $passphrase = $questionHelper->ask($input, $output, $passphraseQuestion);

        if (!is_string($passphrase)) {
            $passphrase = '';
        }

        return $passphrase;
    }
}
