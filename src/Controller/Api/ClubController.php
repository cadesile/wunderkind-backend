<?php

namespace App\Controller\Api;

use App\Dto\ClubInitRequest;
use App\Entity\Investor;
use App\Entity\User;
use App\Repository\ClubRepository;
use App\Service\ClubInitializationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/club')]
class ClubController extends AbstractController
{
    public function __construct(private readonly ClubRepository $clubRepository) {}

    private const CITIES_BY_COUNTRY = [
        'EN' => [
            'Manchester', 'Liverpool', 'London', 'Birmingham', 'Leeds', 'Sheffield',
            'Newcastle', 'Sunderland', 'Nottingham', 'Leicester', 'Bristol', 'Southampton',
            'Coventry', 'Stoke', 'Derby', 'Bolton', 'Blackburn', 'Ipswich', 'Reading',
            'Burnley', 'Huddersfield', 'Preston', 'Wolves', 'Charlton', 'Millwall',
            'Portsmouth', 'Plymouth', 'Hull', 'Middlesbrough', 'Norwich', 'Watford',
            'Bournemouth', 'Brighton', 'Luton', 'Brentford', 'Fulham', 'Blackpool',
            'Bury', 'Stockport', 'Oldham', 'Rochdale', 'Wigan', 'Crewe', 'Swindon',
            'Doncaster', 'Rotherham', 'Barnsley', 'Chesterfield', 'Lincoln', 'Carlisle',
            'Shrewsbury', 'Walsall', 'Colchester', 'Southend', 'Wycombe', 'Exeter'
        ],
        'ES' => [
            'Madrid', 'Barcelona', 'Sevilla', 'Valencia', 'Bilbao', 'Málaga', 'Zaragoza',
            'Valladolid', 'Cádiz', 'Almería', 'Murcia', 'Alicante', 'Santander', 'Vigo',
            'Getafe', 'Leganés', 'Girona', 'Elche', 'Betis', 'Osasuna', 'Granada',
            'Las Palmas', 'Mallorca', 'Villarreal', 'Eibar', 'Tenerife', 'Oviedo', 'Gijón'
        ],
        'DE' => [
            'München', 'Dortmund', 'Hamburg', 'Frankfurt', 'Stuttgart', 'Köln', 'Leipzig',
            'Leverkusen', 'Mönchengladbach', 'Wolfsburg', 'Augsburg', 'Freiburg', 'Mainz',
            'Bochum', 'Bremen', 'Hannover', 'Nürnberg', 'Düsseldorf', 'Bielefeld', 'Paderborn',
            'Schalke', 'Kaiserslautern', 'Hertha', 'Union Berlin', 'Darmstadt', 'Heidenheim', 'St. Pauli'
        ],
        'FR' => [
            'Paris', 'Lyon', 'Marseille', 'Bordeaux', 'Lille', 'Monaco', 'Nantes', 'Nice',
            'Rennes', 'Montpellier', 'Saint-Étienne', 'Strasbourg', 'Lens', 'Reims',
            'Brest', 'Metz', 'Lorient', 'Angers', 'Troyes', 'Clermont', 'Toulouse',
            'Le Havre', 'Auxerre', 'Corsica', 'Ajaccio', 'Bastia', 'Dijon'
        ],
        'IT' => [
            'Roma', 'Milano', 'Torino', 'Napoli', 'Firenze', 'Genova', 'Venezia', 'Lazio',
            'Bologna', 'Verona', 'Atalanta', 'Sassuolo', 'Monza', 'Lecce', 'Udinese',
            'Empoli', 'Salernitana', 'Spezia', 'Cremonese', 'Cagliari', 'Palermo',
            'Bari', 'Parma', 'Modena', 'Pisa', 'Como', 'Brescia', 'Reggiana'
        ],
        'AR' => [
            'Buenos Aires', 'Avellaneda', 'Rosario', 'Córdoba', 'Santa Fe', 'La Plata', 
            'Mendoza', 'Tucumán', 'Mar del Plata', 'Salta', 'San Juan', 'Lanús', 
            'Banfield', 'Quilmes', 'Tigre', 'Bahía Blanca', 'Paraná', 'Santiago del Estero',
            'Resistencia', 'Corrientes', 'Jujuy', 'Posadas', 'Rafaela', 'Junín'
        ],
        'BR' => [
            'São Paulo', 'Rio de Janeiro', 'Belo Horizonte', 'Porto Alegre', 'Curitiba', 
            'Salvador', 'Recife', 'Fortaleza', 'Goiânia', 'Belém', 'Manaus', 'Florianópolis', 
            'Santos', 'Campinas', 'Caxias do Sul', 'Cuiabá', 'Natal', 'São Luís', 
            'Maceió', 'João Pessoa', 'Chapecoense', 'Joinville', 'Ribeirão Preto', 'Juiz de Fora'
        ],
    ];

    private const SUFFIXES = [
        'EN' => [
            'FC', 'United', 'City', 'Athletic', 'Rovers', 'Town', 'Rangers', 
            'Wednesday', 'Albion', 'Wanderers', 'Vale', 'Orient', 'County', 
            'Villa', 'North End', 'Alexandra', 'Harriers', 'Hotspur'
        ],
        'ES' => [
            'CF', 'CD', 'UD', 'Real', 'Atlético', 'SD', 'Deportivo', 'Unión', 
            'Club', 'Sporting', 'Racing', 'RC', 'RCD'
        ],
        'DE' => [
            'SV', 'VfL', 'VfB', 'FC', 'SC', 'FSV', 'SpVgg', 'Borussia', 
            'Eintracht', 'Hertha', 'Dynamo', 'Germania', 'Preußen', 'Fortuna'
        ],
        'FR' => [
            'FC', 'AS', 'Olympique', 'Stade', 'RC', 'US', 'SC', 'SCO', 
            'Racing Club', 'Girondins', 'AJ', 'CS'
        ],
        'IT' => [
            'AC', 'FC', 'AS', 'US', 'Calcio', 'Virtus', 'Sporting', 'Polisportiva', 
            'Città di', 'Real', 'Unione Sportiva', 'Atalanta'
        ],
        'AR' => [
            'CA', 'Club Atlético', 'Deportivo', 'Social y Deportivo', 'Racing', 
            'Unión', 'Gimnasia y Esgrima', 'Sportivo', 'Defensores de', 'Juventud Unida'
        ],
        'BR' => [
            'FC', 'EC', 'CR', 'AC', 'Botafogo', 'Sport', 'Grêmio', 'Sociedade Esportiva', 
            'Clube do Remo', 'Náutico', 'Juventude', 'Operário'
        ],
    ];

    #[Route('/name-options', name: 'api_clubs_name_options', methods: ['GET'])]
    public function nameOptions(Request $request): JsonResponse
    {
        $country = strtoupper($request->query->get('country', 'EN'));
        $cities  = self::CITIES_BY_COUNTRY[$country] ?? self::CITIES_BY_COUNTRY['EN'];
        $suffixes  = self::SUFFIXES[$country] ?? self::SUFFIXES['EN'];

        return $this->json([
            'country'  => $country,
            'cities'   => $cities,
            'suffixes' => $suffixes,
        ]);
    }

    #[Route('/initialize', name: 'api_club_initialize', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function initialize(
        #[MapRequestPayload] ClubInitRequest $dto,
        ClubInitializationService $service,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $managerProfile = $dto->manager !== null ? [
            'name'        => $dto->manager->name,
            'dateOfBirth' => $dto->manager->dateOfBirth,
            'gender'      => $dto->manager->gender,
            'nationality' => $dto->manager->nationality,
        ] : null;

        try {
            $club = $service->initializeClub($user, $dto->clubName, $dto->country, $managerProfile);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'id'            => $club->getId()->toRfc4122(),
            'name'          => $club->getName(),
            'starterBundle' => $service->getStarterBundle(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/check', name: 'api_club_check', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function check(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $club = $this->clubRepository->findByUser($user);

        if ($club === null) {
            return $this->json(['exists' => false, 'reason' => 'club_not_found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['exists' => true, 'clubId' => $club->getId()->toRfc4122()]);
    }

    #[Route('/status', name: 'api_club_status', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function status(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $club = $this->clubRepository->findByUser($user);

        if ($club === null) {
            return $this->json(['error' => 'No club found.'], Response::HTTP_NOT_FOUND);
        }

        $activeInvestorCount = $club->getInvestors()
            ->filter(fn (Investor $i) => $i->isActive())
            ->count();

        return $this->json([
            'id'                  => $club->getId()->toRfc4122(),
            'name'                => $club->getName(),
            'abbreviation'        => $club->getAbbreviation(),
            'balance'             => $club->getBalance(),
            'hasDebt'             => $club->hasDebt(),
            'reputation'          => $club->getReputation(),
            'weekNumber'          => $club->getLastSyncedWeek(),
            'totalCareerEarnings' => $club->getTotalCareerEarnings(),
            'hallOfFamePoints'    => $club->getHallOfFamePoints(),
            'playerCount'         => $club->getPlayers()->count(),
            'staffCount'          => $club->getStaff()->count(),
            'activeSponsors'      => $club->getActiveSponsors()->count(),
            'activeInvestors'     => $activeInvestorCount,
        ]);
    }
}
