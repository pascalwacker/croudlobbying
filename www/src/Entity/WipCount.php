<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\WipCountRepository")
 */
class WipCount
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="integer")
     */
    private $status;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $voted;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Campaign", inversedBy="wipCounts")
     * @ORM\JoinColumn(nullable=false)
     */
    private $campaign;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Politician")
     * @ORM\JoinColumn(nullable=false)
     */
    private $politician;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getVoted(): ?int
    {
        return $this->voted;
    }

    public function setVoted(?int $voted): self
    {
        $this->voted = $voted;

        return $this;
    }

    public function getCampaign(): ?Campaign
    {
        return $this->campaign;
    }

    public function setCampaign(?Campaign $campaign): self
    {
        $this->campaign = $campaign;

        return $this;
    }

    public function getPolitician(): ?Politician
    {
        return $this->politician;
    }

    public function setPolitician(?Politician $politician): self
    {
        $this->politician = $politician;

        return $this;
    }
}
