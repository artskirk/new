<?php

namespace Datto\App\Console\Command\Restore\Iscsi;

use Datto\App\Console\Command\CommandValidator;
use Datto\App\Console\Command\RequiresInteractivePassphrase;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\AssetService;
use Datto\Restore\Iscsi\IscsiMounterService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @author Peter Ruczynski <pruczynski@datto.com>
 */
class CreateTargetCommand extends AbstractIscsiCommand
{
    use RequiresInteractivePassphrase;

    protected static $defaultName = 'restore:iscsi:create';

    /** @var AssetService */
    private $assetService;

    /** @var TempAccessService */
    private $tempAccessService;

    /** @var EncryptionService */
    private $encryptionService;

    public function __construct(
        TempAccessService $tempAccessService,
        EncryptionService $encryptionService,
        AssetService $assetService,
        CommandValidator $commandValidator,
        IscsiMounterService $iscsiMounterService
    ) {
        parent::__construct($commandValidator, $iscsiMounterService);

        $this->tempAccessService = $tempAccessService;
        $this->encryptionService = $encryptionService;
        $this->assetService = $assetService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Create an iSCSI target for a cloned snapshot.')
            ->addOption('agent', 's', InputOption::VALUE_REQUIRED, 'Name of the agent to base the iSCSI target on.')
            ->addOption('snapshot', 'S', InputOption::VALUE_REQUIRED, "Snapshot of the agent to base the iSCSI target on.")
            ->addOption('blockSize', 'B', InputOption::VALUE_OPTIONAL, 'Block size of this share. 512 or 4096', '4096')
            ->addOption('mode', 'm', InputOption::VALUE_OPTIONAL, "Target mount mode, e.g. export or virtualization.", IscsiMounterService::MODE_EXPORT);
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validator->validateValue($input->getOption('agent'), new Assert\NotNull(), 'Agent must be specified');
        $this->validator->validateValue($input->getOption('snapshot'), new Assert\NotNull(), 'Snapshot must be specified');
        $this->validator->validateValue($input->getOption('snapshot'), new Assert\Regex(array('pattern' => "~^[[:graph:]]+$~")), 'Snapshot must be alphanumeric');
        $this->validator->validateValue($input->getOption('blockSize'), new Assert\Regex(array('pattern' => '/^(512)|(4096)$/')), 'Blocksize must either be 512 or 4096');
        $this->validator->validateValue($input->getOption('mode'), new Assert\Regex(array('pattern' => "~^[export|virtualization]+$~")), 'Invalid mode, use either "export" or "virtualization"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $agent = $input->getOption('agent');
        $snapshot = $input->getOption('snapshot');
        $blockSize = $input->getOption('blockSize');
        $mode = $input->getOption('mode');

        $asset = $this->assetService->get($input->getOption('agent'));
        $passphrase = $this->promptAgentPassphraseIfRequired($asset, $this->tempAccessService, $input, $output);

        $this->iscsiMounter->setMode($mode);
        $this->iscsiMounter->createClone($agent, $snapshot, $passphrase);
        $targetName = $this->iscsiMounter->createIscsiTarget($agent, $snapshot, false, null, $blockSize);
        // TODO: IscsiMounterService::[add|remove]Restore were hard-coding suffix to SUFFIX_RESTORE,
        //       whereas createIscsiTarget call above was using SUFFIX_EXPORT in target name, therefore,
        //       we have to mimic this bug to not break ability to tear down any exising iscsi restores
        //       created by this CLI command :-/
        $this->iscsiMounter->setSuffix(IscsiMounterService::SUFFIX_RESTORE);
        $this->iscsiMounter->addRestore($agent, $snapshot, $targetName);
        return 0;
    }
}
