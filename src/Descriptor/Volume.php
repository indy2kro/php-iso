<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use Carbon\Carbon;
use PhpIso\Descriptor;
use PhpIso\FileDirectory;
use PhpIso\IsoFile;
use PhpIso\Util\Buffer;
use PhpIso\Util\IsoDate;

abstract class Volume extends Descriptor
{
    public int $Unused1OrFlag;
    public string $stra_SystemId;
    public string $strd_VolumeId;
    public string $byUnused2;
    public int $i_bbo_VolSpaceSize;
    public string $EscapeSequences;
    public int $iVolSetSize;
    public int $iVolSeqNum;
    public int $iBlockSize;
    public int $iPathTableSize;
    public int $i_lsb_LPathTablePos;
    public int $i_lsb_OptLPathTablePos;
    public int $i_msb_MPathTablePos;
    public int $i_msb_OptMPathTablePos;
    public FileDirectory $byRootDirRec;
    public string $strd_VolSetId;
    public string $stra_PublisherId;
    public string $stra_PreparerId;
    public string $stra_AppId;
    public string $strd_CopyrightFileId;
    public string $strd_AbstractFileId;
    public string $strd_BibliographicFileId;
    public ?Carbon $dtCreation = null;
    public ?Carbon $dtModification = null;
    public ?Carbon $dtExpiration = null;
    public ?Carbon $dtEffective = null;
    public int $byFileStructureVersion;

    public function init(IsoFile $isoFile, int &$offset): void
    {
        if ($this->bytes === null) {
            return;
        }

        $this->Unused1OrFlag = $this->bytes[$offset];
        $offset++;

        $this->stra_SystemId = Buffer::readAString($this->bytes, 32, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC));
        $this->strd_VolumeId = Buffer::readDString($this->bytes, 32, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC));

        $this->byUnused2 = Buffer::getBytes($this->bytes, 8, $offset);

        $this->i_bbo_VolSpaceSize = Buffer::readBBO($this->bytes, 8, $offset);

        $this->EscapeSequences = Buffer::getBytes($this->bytes, 32, $offset);

        $this->iVolSetSize = Buffer::readBBO($this->bytes, 4, $offset);
        $this->iVolSeqNum = Buffer::readBBO($this->bytes, 4, $offset);
        $this->iBlockSize = Buffer::readBBO($this->bytes, 4, $offset);
        $this->iPathTableSize = Buffer::readBBO($this->bytes, 8, $offset);

        $this->i_lsb_LPathTablePos = Buffer::readLSB($this->bytes, 4, $offset);
        $this->i_lsb_OptLPathTablePos = Buffer::readLSB($this->bytes, 4, $offset);
        $this->i_msb_MPathTablePos = Buffer::readMSB($this->bytes, 4, $offset);
        $this->i_msb_OptMPathTablePos = Buffer::readMSB($this->bytes, 4, $offset);

        $this->byRootDirRec = new FileDirectory();
        $this->byRootDirRec->Init($this->bytes, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC));

        $this->strd_VolSetId = Buffer::readDString($this->bytes, 128, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC));
        $this->stra_PublisherId = Buffer::readAString($this->bytes, 128, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC));
        $this->stra_PreparerId = Buffer::readAString($this->bytes, 128, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC));
        $this->stra_AppId = Buffer::readAString($this->bytes, 128, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC));

        $this->strd_CopyrightFileId = Buffer::readDString($this->bytes, 37, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC));
        $this->strd_AbstractFileId = Buffer::readDString($this->bytes, 37, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC));

        $this->strd_BibliographicFileId = Buffer::readDString($this->bytes, 37, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC));

        $this->dtCreation = IsoDate::init17($this->bytes, $offset);

        $this->dtModification = IsoDate::init17($this->bytes, $offset);

        $this->dtExpiration = IsoDate::init17($this->bytes, $offset);

        $this->dtEffective = IsoDate::init17($this->bytes, $offset);

        $this->byFileStructureVersion = $this->bytes[$offset];
        $offset++;

        $this->stra_SystemId = trim($this->stra_SystemId);
        $this->strd_VolumeId = trim($this->strd_VolumeId);
        $this->strd_VolSetId = trim($this->strd_VolSetId);
        $this->stra_PublisherId = trim($this->stra_PublisherId);
        $this->stra_PreparerId = trim($this->stra_PreparerId);
        $this->stra_AppId = trim($this->stra_AppId);
        $this->strd_CopyrightFileId = trim($this->strd_CopyrightFileId);
        $this->strd_AbstractFileId = trim($this->strd_AbstractFileId);
        $this->strd_BibliographicFileId = trim($this->strd_BibliographicFileId);

        // free some space...
        unset($this->bytes);
    }
}
