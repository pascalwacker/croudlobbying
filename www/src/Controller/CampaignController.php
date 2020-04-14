<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Argument;
use App\Entity\Campaign;
use App\Entity\CampaignEntry;
use App\Entity\Person;
use App\Entity\Politician;
use App\Entity\Region;
use App\Form\PersonType;
use App\Repository\ArgumentRepository;
use App\Repository\CampaignEntryRepository;
use App\Repository\PersonRepository;
use App\Repository\PoliticianRepository;
use App\Utils\TokenGenerator;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as MVC;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @Route(
 *     "/{_locale}",
 *     host="{campaign}.{domain}",
 *     defaults={"domain"="%domain%"},
 *     requirements={"campaign"="[\w-]+", "_locale"="de|fr|", "domain"="%domain%"}
 * )
 */
class CampaignController extends AbstractController
{
    public static function getSubscribedServices()
    {
        $services = parent::getSubscribedServices();
        $services += [
            \Swift_Mailer::class,
            CampaignEntryRepository::class,
            PoliticianRepository::class,
            ArgumentRepository::class,
            PersonRepository::class,
            TokenGenerator::class,
        ];

        return $services;
    }

    /**
     * @Route("/", name="app_campaign_index", methods={"GET"}, requirements={"region"="\d+"})
     * @MVC\ParamConverter("campaign", options={"mapping": {"campaign": "slug"}})
     */
    public function index(Campaign $campaign, Request $request): Response
    {
        if ('GET' === $request->getMethod() && $request->getLocale() !== $request->get('_locale')) {
            return $this->redirectToRoute('app_campaign_index', [
                'campaign' => $campaign->getSlug(),
                '_locale' => $request->getLocale(),
            ]);
        }

        $campaignEntryRepository = $this->get(CampaignEntryRepository::class);
        $politicianRepository = $this->get(PoliticianRepository::class);

        $entries = $campaignEntryRepository->findBy(
            ['campaign' => $campaign],
            ['id' => 'desc'],
            10
        );

        shuffle($entries);

        return $this->render('campaign/index.html.twig', [
            'campaign' => $campaign,
            'politicians' => $politicianRepository->findByCampaign($campaign),
            'latestEntries' => $entries,
            'total' => \count($campaignEntryRepository->findBy(['campaign' => $campaign])),
        ]);
    }

    /**
     * @Route("/{region}", name="app_campaign_region_redirect", methods={"GET"}, requirements={"region"="\d+"})
     * @MVC\ParamConverter("campaign", options={"mapping": {"campaign": "slug"}})
     * @MVC\ParamConverter("region", options={"mapping": {"region": "id"}})
     */
    public function regionRedirect(Campaign $campaign, Request $request, Region $region = null): RedirectResponse
    {
        if ('GET' === $request->getMethod() && $request->getLocale() !== $request->get('_locale')) {
            return $this->redirectToRoute('app_campaign_region_redirect', [
                'campaign' => $campaign->getSlug(),
                'region' => $region ? $region->getSlug() : null,
                '_locale' => $request->getLocale(),
            ]);
        }

        return $this->redirectToRoute('app_campaign_region', [
            'campaign' => $campaign->getSlug(),
            'region' => $region ? $region->getSlug() : null,
            '_locale' => $request->getLocale(),
        ]);
    }

    /**
     * @Route("/{region}", name="app_campaign_region", methods={"GET"}, requirements={"region"="[\w]{2}"})
     * @MVC\ParamConverter("campaign", options={"mapping": {"campaign": "slug"}})
     * @MVC\ParamConverter("region", options={"mapping": {"region": "slug"}})
     */
    public function region(Campaign $campaign, Request $request, Region $region = null): Response
    {
        if ('GET' === $request->getMethod() && $request->getLocale() !== $request->get('_locale')) {
            return $this->redirectToRoute('app_campaign_region', [
                'campaign' => $campaign->getSlug(),
                'region' => $region ? $region->getSlug() : null,
                '_locale' => $request->getLocale(),
            ]);
        }

        $campaignEntryRepository = $this->get(CampaignEntryRepository::class);
        $politicianRepository = $this->get(PoliticianRepository::class);

        $entries = $campaignEntryRepository->findBy(
            ['campaign' => $campaign],
            ['id' => 'desc'],
            10
        );

        shuffle($entries);

        return $this->render('campaign/index.html.twig', [
            'campaign' => $campaign,
            'politicians' => $politicianRepository->findByTypeAndRegions($campaign->getPoliticianType(), [$region]),
            'latestEntries' => $entries,
            'total' => \count($campaignEntryRepository->findBy(['campaign' => $campaign])),
            'region' => $region,
        ]);
    }

    /**
     * @Route("/lobby/{slug}", name="app_campaign_lobby", methods={"GET", "POST"}, requirements={"slug"="[\w\.-]+"})
     * @MVC\ParamConverter("campaign", options={"mapping": {"campaign": "slug"}})
     * @MVC\ParamConverter("politician", options={"mapping": {"slug": "slug"}})
     */
    public function lobby(Campaign $campaign, Politician $politician, Request $request): Response
    {
        if ('GET' === $request->getMethod() && $request->getLocale() !== $request->get('_locale')) {
            return $this->redirectToRoute('app_campaign_lobby', [
                'campaign' => $campaign->getSlug(),
                'slug' => $politician->getSlug(),
                '_locale' => $request->getLocale(),
            ]);
        }

        $argumentRepository = $this->get(ArgumentRepository::class);
        $personRepository = $this->get(PersonRepository::class);

        $person = new Person();
        $form = $this->createForm(PersonType::class, $person);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->isCsrfTokenValid('person', $request->request->get('token'))) {
            $argument = $argumentRepository->findOneBy([
                'campaign' => $campaign,
                'id' => $request->request->get('argument', 0),
            ]);

            if ($argument) {
                /** @var Person $person */
                $person = $form->getData();

                $em = $this->getDoctrine()->getManager();
                $existingPerson = $personRepository->findOneBy(['email' => $person->getEmail()]);

                if ($existingPerson) {
                    // update person if something has changed
                    // doctrine will take care of the changeset...
                    $existingPerson->setFirstname($person->getFirstname());
                    $existingPerson->setLastname($person->getLastname());
                    $existingPerson->setCity($person->getCity());

                    $person = $existingPerson;
                }

                if (!$person->isConfirmed() && null === $person->getConfirmationToken()) {
                    $person->setConfirmationToken($this->get(TokenGenerator::class)->generateToken());
                    $person->setConfirmationExpires(new \DateTime('+7 days'));
                }

                $person->setLanguage($request->getLocale());
                $em->persist($person);

                $campaignEntry = $this->createCampaignEntry($request, $person, $campaign, $argument, $politician);

                if ($person->isConfirmed()) {
                    $campaignEntry->setConfirmed(true);
                    $em->persist($campaignEntry);

                    $this->sendThanksMail($person, $politician, $campaign);

                    return $this->redirectToRoute('app_campaign_thanks', ['campaign' => $campaign->getSlug(), 'id' => $campaignEntry->getId()]);
                }

                $this->sendConfirmationMail($person, $politician, $campaign, $argument);

                return $this->redirectToRoute('app_campaign_confirm', ['campaign' => $campaign->getSlug(), 'id' => $campaignEntry->getId()]);
            }
        }

        return $this->render('campaign/lobby.html.twig', [
            'campaign' => $campaign,
            'politician' => $politician,
            'form' => $form->createView(),
            'person' => $person,
        ]);
    }

    /**
     * @Route("/lobby/{slug}/confirm/{token}", name="app_campaign_lobby_confirm", methods={"GET"}, requirements={"slug"="[\w\.-]+"})
     * @MVC\ParamConverter("campaign", options={"mapping": {"campaign": "slug"}})
     * @MVC\ParamConverter("politician", options={"mapping": {"slug": "slug"}})
     */
    public function lobbyConfirm(Campaign $campaign, Politician $politician, string $token, Request $request): Response
    {
        /** @var Person|null $person */
        $person = $this->get(PersonRepository::class)->findOneBy([
            'confirmationToken' => $token,
        ]);
        $em = $this->getDoctrine()->getManager();

        if (null === $person) {
            throw new \RuntimeException('Could not find confirmation token.');
        }

        if ($person->getConfirmationExpires() < new \DateTime()) {
            throw new \RuntimeException('Confirmation token expired.');
        }

        /** @var CampaignEntry|null $campaignEntry */
        $campaignEntry = $this->get(CampaignEntryRepository::class)->findOneBy([
            'person' => $person,
            'campaign' => $campaign,
            'politician' => $politician,
        ]);

        if (null === $campaignEntry) {
            throw new \RuntimeException('Could not find campaign.');
        }

        $person->setConfirmed(true);
        $person->setConfirmationToken(null);
        $person->setConfirmationExpires(null);

        $campaignEntry->setConfirmed(true);

        $em->persist($person);
        $em->persist($campaignEntry);
        $em->flush();

        $this->sendThanksMail($person, $politician, $campaign);

        return $this->redirectToRoute('app_campaign_thanks', [
            'campaign' => $campaign->getSlug(),
            'id' => $campaignEntry->getId(),
        ]);
    }

    /**
     * @Route("/thanks/{id}", name="app_campaign_thanks", methods={"GET"}, requirements={"id"="\d+"})
     * @MVC\ParamConverter("campaign", options={"mapping": {"campaign": "slug"}})
     */
    public function thanks(Campaign $campaign, CampaignEntry $campaignEntry, Request $request): Response
    {
        if ('GET' === $request->getMethod() && $request->getLocale() !== $request->get('_locale')) {
            return $this->redirectToRoute('app_campaign_thanks', [
                'campaign' => $campaign->getSlug(),
                'id' => $campaignEntry->getId(),
                '_locale' => $request->getLocale(),
            ]);
        }

        return $this->render('campaign/thanks.html.twig', [
            'campaign' => $campaign,
            'campaignEntry' => $campaignEntry,
        ]);
    }

    /**
     * @Route("/confirm/{id}", name="app_campaign_confirm", methods={"GET"}, requirements={"id"="\d+"})
     * @MVC\ParamConverter("campaign", options={"mapping": {"campaign": "slug"}})
     */
    public function confirm(Campaign $campaign, CampaignEntry $campaignEntry, Request $request): Response
    {
        return $this->render('campaign/confirm.html.twig', [
            'campaign' => $campaign,
            'campaignEntry' => $campaignEntry,
        ]);
    }

    /**
     * @Route("/statements", name="app_campaign_statements", methods={"GET"}, defaults={"id"="0"})
     * @Route("/statement/{id}", name="app_campaign_statement", methods={"GET"}, requirements={"id"="\d+"})
     * @MVC\ParamConverter("campaign", options={"mapping": {"campaign": "slug"}})
     */
    public function statements(Campaign $campaign, Request $request, CampaignEntry $campaignEntry = null): Response
    {
        if ('GET' === $request->getMethod() && $request->getLocale() !== $request->get('_locale')) {
            if ($request->query->get('id', 0) > 0) {
                return $this->redirectToRoute('app_campaign_statement', [
                    'campaign' => $campaign->getSlug(),
                    'id' => $request->query->get('id'),
                    '_locale' => $request->getLocale(),
                ]);
            }

            return $this->redirectToRoute('app_campaign_statements', [
                'campaign' => $campaign->getSlug(),
                '_locale' => $request->getLocale(),
            ]);
        }

        return $this->render('campaign/statements.html.twig', [
            'campaign' => $campaign,
            'campaignEntry' => $campaignEntry,
        ]);
    }

    private function createCampaignEntry(Request $request, Person $person, Campaign $campaign, Argument $argument, Politician $politician): CampaignEntry
    {
        $em = $this->getDoctrine()->getManager();
        $repository = $this->get(CampaignEntryRepository::class);

        $existing = $repository->findOneBy([
            'politician' => $politician,
            'person' => $person,
            'argument' => $argument,
            'campaign' => $campaign,
        ]);

        if ($existing instanceof CampaignEntry) {
            return $existing;
        }

        $campaignEntry = new CampaignEntry();
        $campaignEntry->setOptInInformation((bool) ($request->request->get('optInInformation', 0)));
        $campaignEntry->setPerson($person);
        $campaignEntry->setCampaign($campaign);
        $campaignEntry->setArgument($argument);
        $campaignEntry->setPolitician($politician);
        $campaignEntry->setColor($campaignEntry->getRandomColor());

        $em->persist($campaignEntry);
        $em->flush();

        return $campaignEntry;
    }

    private function sendThanksMail(Person $person, Politician $politician, Campaign $campaign): void
    {
        // @TODO call PDF generator
        $message = (new \Swift_Message('Crowd-Lobbying'))
            ->setFrom('crowd-lobbying@publicbeta.ch')
            ->setTo($person->getEmail())
            ->setBody(
                $this->renderView(
                    'emails/thanks.html.twig', [
                        'person' => $person,
                        'politician' => $politician,
                        'campaign' => $campaign,
                        ]),
                'text/html'
            );

        $this->get(\Swift_Mailer::class)->send($message);
    }

    private function sendConfirmationMail(Person $person, Politician $politician, Campaign $campaign, Argument $argument): void
    {
        $router = $this->get('router');

        $message = (new \Swift_Message('Crowd-Lobbying: Bitte bestätigen Sie Ihre Nachricht '))
            ->setFrom('crowd-lobbying@publicbeta.ch')
            ->setTo($person->getEmail())
            ->setBody(
                $this->renderView(
                    'emails/confirmation.html.twig', [
                        'person' => $person,
                        'politician' => $politician,
                        'campaign' => $campaign,
                        'argument' => $argument,
                        'urlConfirmation' => $router->generate('app_campaign_lobby_confirm', [
                            'slug' => $politician->getSlug(),
                            'campaign' => $campaign,
                            'token' => $person->getConfirmationToken(),
                        ], UrlGeneratorInterface::ABSOLUTE_URL),
                        'urlDonate' => $router->generate('app_campaign_index', [
                            'slug' => null,
                            'campaign' => $campaign,
                        ], UrlGeneratorInterface::ABSOLUTE_URL),
                    ]),
                'text/html'
            );

        $this->get(\Swift_Mailer::class)->send($message);
    }
}
