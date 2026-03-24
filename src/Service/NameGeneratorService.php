<?php

declare(strict_types=1);

namespace App\Service;

class NameGeneratorService
{
    private const NATIONALITIES = [
        'English', 'Spanish', 'French', 'German', 'Brazilian', 'Portuguese',
        'Nigerian', 'Ghanaian', 'Japanese', 'South Korean', 'Argentine',
        'Dutch', 'Italian', 'Swedish', 'Danish', 'Irish', 'Ivorian', 'Senegalese', 'Chinese',
    ];

    public function generateName(string $nationality): string
    {
        $pools = self::getNamePools();

        if (!isset($pools[$nationality])) {
            throw new \InvalidArgumentException("Unknown nationality: {$nationality}");
        }

        $pool      = $pools[$nationality];
        $firstName = $pool['firstNames'][array_rand($pool['firstNames'])];

        // Brazilian: 20% chance of mononym (single name only)
        if ($nationality === 'Brazilian' && random_int(1, 5) === 1) {
            return $firstName;
        }

        $lastName = $pool['lastNames'][array_rand($pool['lastNames'])];

        return "{$firstName} {$lastName}";
    }

    public function getRandomNationality(): string
    {
        return self::NATIONALITIES[array_rand(self::NATIONALITIES)];
    }

    private static function getNamePools(): array
    {
        return [

            // -----------------------------------------------------------------
            // English
            // -----------------------------------------------------------------
            'English' => [
                'firstNames' => [
                    'Alistair', 'Beatrice', 'Callum', 'Dorothy', 'Ewan', 'Felicity', 'Gideon',
                    'Hazel', 'Iris', 'Jasper', 'Kieran', 'Lydia', 'Milo', 'Nora', 'Oscar',
                    'Penelope', 'Quentin', 'Rose', 'Silas', 'Tabitha', 'Victor', 'Winifred',
                    'Arthur', 'Clara', 'Desmond', 'Edith', 'Franklin', 'Georgia', 'Hugo', 'Imogen',
                    'Jude', 'Keira', 'Leopold', 'Margot', 'Nathaniel', 'Olive', 'Phineas', 'Romilly',
                    'Sebastian', 'Thea', 'Ulysses', 'Violet', 'Wyatt', 'Xanthe', 'Yorick', 'Zara',
                    'Barnaby', 'Elspeth', 'Hamish', 'Verity',
                ],
                'lastNames' => [
                    'Thorne', 'Sterling', 'Huxley', 'Beaumont', 'Blackwood', 'Whittaker', 'Sinclair',
                    'Graves', 'Pemberton', 'Ashworth', 'Gable', 'Hawthorn', 'Kensington', 'Ledger',
                    'Prescott', 'Rhodes', 'Talbot', 'Vance', 'Winter', 'Yardley', 'Banks', 'Croft',
                    'Davenport', 'Ellwood', 'Finch', 'Grier', 'Holt', 'Ives', 'Jago', 'Kemp',
                    'Lowen', 'Mercer', 'Nash', 'Orton', 'Pike', 'Quirk', 'Royce', 'Stoker',
                    'Teale', 'Upton', 'Vane', 'Wild', 'Yates', 'Archer', 'Booth', 'Crane',
                    'Drake', 'Frost', 'Hyde', 'Moss',
                ],
            ],

            // -----------------------------------------------------------------
            // Spanish
            // -----------------------------------------------------------------
            'Spanish' => [
                'firstNames' => [
                    'Mateo', 'Aitana', 'Joaquín', 'Lucía', 'Thiago', 'Valeria', 'Bruno', 'Jimena',
                    'Gael', 'Ximena', 'Iker', 'Arantxa', 'Santiago', 'Marisol', 'Fermín', 'Elena',
                    'Rodrigo', 'Belén', 'Álvaro', 'Rocío', 'Rafael', 'Nayara', 'Matías', 'Montserrat',
                    'Esteban', 'Inmaculada', 'Hugo', 'Letizia', 'Javier', 'Carmen', 'Alonso', 'Dolores',
                    'Pascual', 'Guadalupe', 'Ignacio', 'Salma', 'Diego', 'Esmeralda', 'Francisco', 'Rosario',
                    'Manuel', 'Blanca', 'Alejandro', 'Yolanda', 'Felipe', 'Esperanza', 'Vicente', 'Paloma',
                    'Enrique', 'Socorro',
                ],
                'lastNames' => [
                    'Navarro', 'Villalobos', 'Montoya', 'Ferrán', 'Mendoza', 'Guerrero', 'Castillo',
                    'Fuentes', 'Delgado', 'Valdéz', 'Espinoza', 'Romero', 'Grijalva', 'Serrano',
                    'Pizarro', 'Vega', 'Ortega', 'Méndez', 'Cortés', 'Salazar', 'Guzmán', 'Beltrán',
                    'Benítez', 'Cabrera', 'Maldonado', 'Ibarra', 'Cisneros', 'Figueroa', 'Gallardo',
                    'Lozano', 'Miranda', 'Pacheco', 'Quintana', 'Roldán', 'Santillán', 'Trejo', 'Uribe',
                    'Vargas', 'Zúñiga', 'Paredes', 'Rosas', 'Gallegos', 'Arenas', 'Belmonte', 'Cárdenas',
                    'Dueñas', 'Estrada', 'Fajardo', 'Heredia', 'Jasso',
                ],
            ],

            // -----------------------------------------------------------------
            // French
            // -----------------------------------------------------------------
            'French' => [
                'firstNames' => [
                    'Adrien', 'Brigitte', 'Cédric', 'Delphine', 'Étienne', 'Faustine', 'Gaël',
                    'Héloïse', 'Isidore', 'Julienne', 'Loïc', 'Maëllys', 'Nicolas', 'Odette', 'Pascal',
                    'Quitterie', 'Rémy', 'Solène', 'Thierry', 'Virginie', 'Yves', 'Zoé', 'Bastien',
                    'Camille', 'Dorian', 'Élodie', 'Fabien', 'Gisèle', 'Honoré', 'Inès', 'Jérôme',
                    'Léonie', 'Maxime', 'Noémie', 'Olivier', 'Pauline', 'Rodolphe', 'Sylvie', 'Tanguy',
                    'Valérie', 'Amaury', 'Béatrice', 'Corentin', 'Estelle', 'Florent', 'Geneviève',
                    'Hugues', 'Josiane', 'Lucile', 'Matthieu',
                ],
                'lastNames' => [
                    'Lefebvre', 'Fontaine', 'Gauthier', 'Morel', 'Bernard', 'Petit', 'Durand',
                    'Leroy', 'Moreau', 'Simon', 'Laurent', 'Michel', 'Thomas', 'Robert', 'Richard',
                    'Dubois', 'Martin', 'Lambert', 'Masson', 'Girard', 'Roux', 'Vincent', 'Faure',
                    'Andre', 'Mercier', 'Blanc', 'Guerin', 'Boyer', 'Garnier', 'Chevalier', 'Legrand',
                    'Perron', 'Boucher', 'Renard', 'Giraud', 'Brun', 'Baron', 'Vidal', 'Dumas',
                    'Brunet', 'Roche', 'Adam', 'Leblanc', 'Dupont', 'Delacroix', 'Lemaire', 'Dumont',
                    'Lecomte', 'Rousseau', 'Le Goff',
                ],
            ],

            // -----------------------------------------------------------------
            // German
            // -----------------------------------------------------------------
            'German' => [
                'firstNames' => [
                    'Hans', 'Klaus', 'Gertrude', 'Friedrich', 'Helmut', 'Ingrid', 'Günter',
                    'Hildegard', 'Werner', 'Brunhilde', 'Otto', 'Lieselotte', 'Rudolf', 'Elfriede',
                    'Ernst', 'Waltraud', 'Hermann', 'Ilse', 'Heinz', 'Ursula', 'Franz', 'Gisela',
                    'Wilhelm', 'Brigitte', 'Karl', 'Renate', 'Walter', 'Edeltraud', 'Erich', 'Marianne',
                    'Lukas', 'Lena', 'Felix', 'Hannah', 'Sophia', 'Jonas', 'Mia', 'Tim', 'Emma',
                    'Niklas', 'Laura', 'Tobias', 'Sarah', 'Maximilian', 'Julia', 'Fabian', 'Katharina',
                    'Sebastian', 'Christine', 'Stefan', 'Anna',
                ],
                'lastNames' => [
                    'Müller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Meyer', 'Wagner',
                    'Becker', 'Schulz', 'Hoffmann', 'Schäfer', 'Koch', 'Bauer', 'Richter', 'Klein',
                    'Wolf', 'Schröder', 'Neumann', 'Schwarz', 'Zimmermann', 'Braun', 'Krüger',
                    'Hofmann', 'Hartmann', 'Lange', 'Schmitt', 'Werner', 'Schmitz', 'Krause', 'Meier',
                    'Lehmann', 'Schmid', 'Schulze', 'Maier', 'Köhler', 'Herrmann', 'König', 'Walter',
                    'Mayer', 'Huber', 'Kaiser', 'Fuchs', 'Peters', 'Lang', 'Scholz', 'Möller',
                    'Weiß', 'Jung', 'Hahn', 'Schubert', 'Breitenberger', 'Schreiber',
                ],
            ],

            // -----------------------------------------------------------------
            // Brazilian
            // -----------------------------------------------------------------
            'Brazilian' => [
                'firstNames' => [
                    'Caio', 'Beatriz', 'Thiago', 'Fernanda', 'Murilo', 'Clarice', 'Renato',
                    'Giovanna', 'Otávio', 'Larissa', 'Heitor', 'Isadora', 'Vinícius', 'Manuela',
                    'Rodrigo', 'Letícia', 'Marcelo', 'Rafaela', 'André', 'Camila', 'Douglas',
                    'Priscila', 'Emerson', 'Tainá', 'Fábio', 'Renata', 'Gustavo', 'Juliana',
                    'Igor', 'Aline', 'Leandro', 'Cláudia', 'Maurício', 'Eliana', 'Nelson',
                    'Patrícia', 'Paulo', 'Simone', 'Ricardo', 'Tereza', 'Sérgio', 'Vanessa',
                    'Wagner', 'Zélia', 'Cristiano', 'Milena', 'Eduardo', 'Débora', 'Henrique', 'Cecília',
                ],
                'lastNames' => [
                    'Oliveira', 'Dos Santos', 'Pereira', 'Costa', 'Silva', 'Rodrigues', 'Almeida',
                    'Nascimento', 'Carvalho', 'Ferreira', 'Ribeiro', 'Sousa', 'Fernandes', 'Lima',
                    'Gomes', 'Marques', 'Rocha', 'Alves', 'Araújo', 'Castro', 'Barbosa', 'Machado',
                    'Cardoso', 'Teixeira', 'Cavalcante', 'Barros', 'Moraes', 'Viana', 'Freitas',
                    'Guimarães', 'Pires', 'Ramos', 'Monteiro', 'Correia', 'Mendes', 'Nunes', 'Lopes',
                    'Soares', 'Viegas', 'Tavares', 'Neves', 'Coelho', 'Cunha', 'Medeiros', 'Fonseca',
                    'Rezende', 'Bittencourt', 'Magalhães', 'Antunes', 'Dantas',
                ],
            ],

            // -----------------------------------------------------------------
            // Portuguese
            // -----------------------------------------------------------------
            'Portuguese' => [
                'firstNames' => [
                    'João', 'Maria', 'António', 'Ana', 'Manuel', 'Sofia', 'Francisco', 'Inês',
                    'Pedro', 'Beatriz', 'Rui', 'Margarida', 'Miguel', 'Catarina', 'Luís', 'Filipa',
                    'Carlos', 'Joana', 'Nuno', 'Marta', 'Diogo', 'Leonor', 'Tiago', 'Raquel',
                    'André', 'Teresa', 'Gonçalo', 'Sónia', 'Duarte', 'Rita', 'Afonso', 'Sandra',
                    'Henrique', 'Carla', 'Ricardo', 'Paula', 'Bruno', 'Patrícia', 'Eduardo', 'Vera',
                    'Sérgio', 'Cláudia', 'Paulo', 'Mónica', 'Rodrigo', 'Daniela', 'Alexandre', 'Susana',
                    'Vasco', 'Natália',
                ],
                'lastNames' => [
                    'Azevedo', 'Figueiredo', 'Sequeira', 'Melo', 'Pacheco', 'Carvalho', 'Ferreira',
                    'Rodrigues', 'Costa', 'Pereira', 'Alves', 'Martins', 'Sousa', 'Santos', 'Gonçalves',
                    'Fernandes', 'Gomes', 'Ribeiro', 'Correia', 'Lopes', 'Nunes', 'Marques', 'Pinto',
                    'Teixeira', 'Cunha', 'Moreira', 'Coelho', 'Vieira', 'Faria', 'Borges', 'Machado',
                    'Monteiro', 'Nogueira', 'Cardoso', 'Pires', 'Amorim', 'Braga', 'Silveira',
                    'Guimarães', 'Tavares', 'Leal', 'Valente', 'Freitas', 'Brito', 'Mendes', 'Simões',
                    'Batista', 'Saraiva', 'Paiva', 'Salgado',
                ],
            ],

            // -----------------------------------------------------------------
            // Nigerian (Yoruba / Igbo / Hausa)
            // -----------------------------------------------------------------
            'Nigerian' => [
                'firstNames' => [
                    // From source
                    'Olumide', 'Babatunde', 'Ifeoma', 'Chidi', 'Nneka', 'Tayo', 'Sade', 'Olatunji',
                    'Temilade', 'Femi', 'Yejide', 'Kehinde', 'Taiwo', 'Eniola',
                    // Yoruba
                    'Adebayo', 'Afolabi', 'Akin', 'Biodun', 'Bolanle', 'Funmi', 'Gbemisola',
                    'Iyabo', 'Jumoke', 'Kemi', 'Kola', 'Kunle', 'Nike', 'Niyi', 'Omotola',
                    'Ronke', 'Seun', 'Shade', 'Tobi', 'Tosin', 'Wale', 'Yemi', 'Bukola',
                    // Igbo
                    'Adaeze', 'Chioma', 'Emeka', 'Ngozi', 'Nnamdi', 'Obinna', 'Ugochukwu',
                    'Uchenna', 'Somto', 'Chidinma', 'Chijioke', 'Ekene',
                    // Hausa
                    'Aliyu', 'Aminu', 'Garba', 'Haruna', 'Ibrahim', 'Kabiru', 'Musa',
                    'Rabiu', 'Sani', 'Usman', 'Yusuf', 'Zainab', 'Abubakar', 'Bello',
                ],
                'lastNames' => [
                    // From source
                    'Balogun', 'Okoro', 'Adeyemi', 'Eze', 'Okafor', 'Nwosu', 'Anyanwu', 'Igbokwe',
                    // Generated Yoruba
                    'Ajayi', 'Ajibade', 'Akinbiyi', 'Akinwale', 'Alabi', 'Ayoola', 'Ayodele',
                    'Bamidele', 'Bakare', 'Dike', 'Fashola', 'Idowu', 'Jegede', 'Lawal',
                    'Obafemi', 'Ogundele', 'Ogundimu', 'Ogunleye', 'Adesanya', 'Adewale',
                    'Adefope', 'Akintola', 'Awolowo', 'Agboola', 'Agoro',
                    // Generated Igbo
                    'Chukwu', 'Ekwueme', 'Nweke', 'Obasi', 'Obi', 'Onwudiwe', 'Onyekachi',
                    'Okonkwo', 'Nnaji', 'Ikenna', 'Ifeanyi', 'Ezeji',
                    // Generated Hausa
                    'Abubakar', 'Aliyu', 'Danladi', 'Musa', 'Shehu', 'Sulaiman',
                    'Umar', 'Yusuf', 'Badawi', 'Gwandu',
                ],
            ],

            // -----------------------------------------------------------------
            // Ghanaian (Akan / Twi day names + wider Ghanaian pool)
            // -----------------------------------------------------------------
            'Ghanaian' => [
                'firstNames' => [
                    // From source (Akan/Twi day names)
                    'Kwame', 'Efua', 'Kofi', 'Adwoa', 'Kojo', 'Abena', 'Kwesi', 'Akua',
                    // Day names (extended)
                    'Kwabena', 'Kweku', 'Yaw', 'Yaa', 'Ama', 'Akosua', 'Paa', 'Kow', 'Ebo',
                    'Araba', 'Kukua', 'Ekua', 'Abenaa', 'Adjoa',
                    // Wider Ghanaian pool
                    'Nana', 'Fiifi', 'Dela', 'Elikem', 'Selorm', 'Selasi', 'Kafui', 'Eyram',
                    'Elom', 'Naki', 'Dzidzor', 'Mawuli', 'Yayra', 'Sena', 'Dzifa', 'Enyonam',
                    'Kwamena', 'Akweley', 'Nyameye', 'Mame', 'Maame', 'Kodzo', 'Kekeli',
                    'Seli', 'Efo', 'Dedzo', 'Aseye', 'Dodzi', 'Soso', 'Senanu',
                    'Korkor', 'Dede', 'Afi', 'Daavi', 'Yawa', 'Dzigbordi',
                    'Kwamina', 'Kwesi', 'Ato', 'Atta', 'Ataa', 'Afua',
                ],
                'lastNames' => [
                    // From source
                    'Mensah', 'Owusu',
                    // Generated
                    'Asante', 'Boateng', 'Osei', 'Amponsah', 'Acheampong', 'Antwi', 'Amoah',
                    'Ofori', 'Asiedu', 'Appiah', 'Oteng', 'Quaye', 'Asamoah', 'Tetteh',
                    'Adjei', 'Sarfo', 'Opoku', 'Amankwah', 'Darko', 'Yeboah', 'Frimpong',
                    'Akoto', 'Aidoo', 'Bonsu', 'Afriyie', 'Nyarko', 'Poku', 'Anane',
                    'Barimah', 'Dankwa', 'Domfeh', 'Duah', 'Gyamfi', 'Kyei', 'Laryea',
                    'Mends', 'Nkrumah', 'Peprah', 'Quaicoe', 'Twum', 'Wiredu', 'Asumadu',
                    'Boadu', 'Ennin', 'Fordjour', 'Sarpong', 'Danso', 'Acheamfour',
                    'Asante-Mensah', 'Baidoo',
                ],
            ],

            // -----------------------------------------------------------------
            // Ivorian (French West African / Dioula / Baoulé)
            // -----------------------------------------------------------------
            'Ivorian' => [
                'firstNames' => [
                    'Abdoulaye', 'Adama', 'Adjoua', 'Affoue', 'Ahou', 'Aissata', 'Akissi',
                    'Alexis', 'Amara', 'Aminata', 'Apollinaire', 'Arsène', 'Barthélémy', 'Cécile',
                    'Clarisse', 'Clément', 'Daouda', 'Drissa', 'Edmond', 'Emmanuel', 'Estelle',
                    'Fatoumata', 'Fernand', 'Firmin', 'Florentin', 'Gilles', 'Habib', 'Inza',
                    'Jean-Baptiste', 'Joseph', 'Julien', 'Karidia', 'Koffi', 'Ladji', 'Mamadou',
                    'Marcel', 'Marie', 'Modeste', 'Moussa', 'Narcisse', 'Noël', 'Oumar', 'Pascal',
                    'Paulin', 'Philippe', 'Pierre', 'Raoul', 'Rodrigue', 'Romuald', 'Samuel',
                    'Sékou', 'Seydou', 'Simone', 'Souleymane', 'Théodore', 'Théophile', 'Wilfried',
                    'Yapi', 'Yacouba', 'Youssouf', 'Zié', 'Valéry', 'Ursule', 'Prisca', 'Rosine',
                ],
                'lastNames' => [
                    // From source
                    'Diakité', 'Coulibaly', 'Koné', 'Sidibé', 'Ouattara', 'Sangaré', 'Sylla',
                    'Cissé', 'Camara', 'Bamba', 'Touré',
                    // Generated
                    'Fofana', 'Konaté', 'Diabaté', 'Doumbia', 'Dembélé', 'Bah', 'Barry',
                    'Baldé', 'Kouyaté', 'Diané', 'N\'Guessan', 'Koffi', 'Yao', 'Kouassi',
                    'Kouadio', 'N\'Goran', 'N\'Zi', 'Assi', 'Brou', 'Kouakou', 'Amoikon',
                    'Djè', 'Gnaman', 'Guei', 'Kassi', 'Kramo', 'Ohouo', 'Sié', 'Tokpa',
                    'Gnahoré', 'Aka', 'Amon', 'Assoumou', 'Blé', 'Dadié', 'Dago', 'Djaha',
                    'Kpan', 'Lobé', 'Yoboué', 'Zoro', 'Zogbo',
                ],
            ],

            // -----------------------------------------------------------------
            // Senegalese (Wolof / French West African)
            // -----------------------------------------------------------------
            'Senegalese' => [
                'firstNames' => [
                    'Abdou', 'Abdoulaye', 'Adja', 'Alioune', 'Aminata', 'Assane', 'Astou',
                    'Bamba', 'Binta', 'Boubacar', 'Cheikh', 'Coumba', 'Daouda', 'Dieynaba',
                    'Fatoumata', 'Ibrahima', 'Ismaïla', 'Khady', 'Lamine', 'Mame', 'Mamadou',
                    'Mariama', 'Mbaye', 'Modou', 'Moussa', 'Ndeye', 'Ndoye', 'Omar', 'Oumou',
                    'Pape', 'Rokhaya', 'Samba', 'Sané', 'Seydou', 'Sokhna', 'Souleymane',
                    'Thierno', 'Yaye', 'Youssou', 'Aissatou', 'Amady', 'Awa', 'Bineta',
                    'Diariatou', 'Elhadji', 'Khadija', 'Mactar', 'Oumar', 'Racine', 'Siga',
                    'Tidiane', 'Malik', 'Aisha', 'Fatou', 'Bara', 'Babacar', 'Ndèye',
                ],
                'lastNames' => [
                    // From source
                    'Diallo', 'Keita', 'Traoré', 'Sow', 'N\'Diaye', 'Diop', 'Fall',
                    // Generated
                    'Ba', 'Gueye', 'Sarr', 'Sy', 'Diouf', 'Faye', 'Wade', 'Mboup', 'Thiam',
                    'Ndour', 'Badji', 'Bassène', 'Coly', 'Dièye', 'Diagne', 'Diatta',
                    'Diédhiou', 'Diémé', 'Dramé', 'Faty', 'Gaye', 'Kanté', 'Manga',
                    'Mané', 'Mendy', 'Niang', 'Sambou', 'Séne', 'Sonko', 'Tendeng',
                    'Thiaw', 'Tounkara', 'Tine', 'Barry', 'Balde', 'Kouyaté', 'Diabaté',
                    'Konaté', 'Camara', 'Mbaye', 'Touré', 'Dème', 'Lô', 'Cissé', 'Kane',
                ],
            ],

            // -----------------------------------------------------------------
            // Japanese (romanised)
            // -----------------------------------------------------------------
            'Japanese' => [
                'firstNames' => [
                    'Kenji', 'Haruka', 'Ren', 'Nanami', 'Shouta', 'Akari', 'Hiroto', 'Yui',
                    'Daiki', 'Sakura', 'Kaito', 'Aoi', 'Ryouta', 'Mio', 'Sora', 'Hana',
                    'Takumi', 'Hina', 'Yuuma', 'Rin', 'Kazuki', 'Mayu', 'Tsubasa', 'Misaki',
                    'Riku', 'Emi', 'Shinnosuke', 'Yuki', 'Kouta', 'Ai', 'Hayato', 'Koharu',
                    'Satoshi', 'Ayano', 'Mitsuki', 'Rina', 'Sosuke', 'Nozomi', 'Daisuke', 'Kana',
                    'Takeshi', 'Shiori', 'Akira', 'Mika', 'Jiro', 'Natsumi', 'Ichiro', 'Yoshiko',
                    'Taro', 'Keiko',
                ],
                'lastNames' => [
                    'Sato', 'Tanaka', 'Nakamura', 'Watanabe', 'Takahashi', 'Ito', 'Kobayashi',
                    'Yamamoto', 'Kato', 'Yoshida', 'Yamada', 'Sasaki', 'Yamaguchi', 'Saito',
                    'Matsumoto', 'Inoue', 'Kimura', 'Hayashi', 'Shimizu', 'Yamazaki', 'Mori',
                    'Abe', 'Ikeda', 'Hashimoto', 'Yamashita', 'Ishikawa', 'Nakajima', 'Maeda',
                    'Fujita', 'Okada', 'Goto', 'Hasegawa', 'Murakami', 'Kondo', 'Ishii',
                    'Sakamoto', 'Endo', 'Aoki', 'Fujii', 'Nishimura', 'Ota', 'Masuda',
                    'Kaneko', 'Okamoto', 'Nakagawa', 'Miura', 'Hara', 'Nakano', 'Ogawa', 'Ueda',
                ],
            ],

            // -----------------------------------------------------------------
            // South Korean (romanised)
            // -----------------------------------------------------------------
            'South Korean' => [
                'firstNames' => [
                    'Minho', 'Jiyeon', 'Seunghyun', 'Hyejin', 'Donghyun', 'Yuna', 'Jungkook',
                    'Chaeyoung', 'Taehyung', 'Sooyeon', 'Jisoo', 'Minji', 'Namjoon', 'Seohyun',
                    'Junhoe', 'Dahyun', 'Sungmin', 'Eunha', 'Yongjun', 'Jiwon', 'Hyunjin',
                    'Nayeon', 'Jooheon', 'Minyoung', 'Kibum', 'Soojin', 'Woohyun', 'Eunji',
                    'Myungsoo', 'Yeji', 'Sanghyun', 'Siyeon', 'Jihoon', 'Wheein', 'Junyoung',
                    'Moonbyul', 'Seokjin', 'Solar', 'Jinwoo', 'Yunho', 'Changsub', 'Hyunwoo',
                    'Yerin', 'Jiho', 'Sowon', 'Eunwoo', 'Yoohyeon', 'Wonho', 'Shownu', 'Kihyun',
                ],
                'lastNames' => [
                    'Kim', 'Lee', 'Park', 'Choi', 'Jung', 'Kang', 'Cho', 'Yoon', 'Lim', 'Han',
                    'Oh', 'Shin', 'Kwon', 'Jang', 'Yang', 'Hwang', 'Song', 'Hong', 'Ko', 'Moon',
                    'Son', 'Ahn', 'Baek', 'Noh', 'Sim', 'Jeon', 'Nam', 'Jeong', 'Seo', 'Im',
                    'Ryu', 'Ha', 'Yoo', 'Ma', 'Ku', 'Hyun', 'Pyo', 'Bae', 'Wi', 'Eom',
                    'Ok', 'Joo', 'Cha', 'Do', 'Min', 'Seok', 'Byun', 'Cheon', 'Tae', 'Goo',
                ],
            ],

            // -----------------------------------------------------------------
            // Argentine (Spanish + heavy Italian influence)
            // -----------------------------------------------------------------
            'Argentine' => [
                'firstNames' => [
                    'Matías', 'Valentina', 'Agustín', 'Florencia', 'Nicolás', 'Sofía', 'Facundo',
                    'Luciana', 'Santiago', 'Camila', 'Ezequiel', 'Romina', 'Leandro', 'Sabrina',
                    'Rodrigo', 'Natalia', 'Sebastián', 'Valeria', 'Germán', 'Verónica', 'Gonzalo',
                    'Lorena', 'Pablo', 'Carolina', 'Diego', 'Paola', 'Fernando', 'Silvina',
                    'Cristian', 'Vanina', 'Andrés', 'Débora', 'Ramiro', 'Estela', 'Sergio',
                    'Mariela', 'Julio', 'Claudia', 'Esteban', 'Liliana', 'Hernán', 'Patricia',
                    'Ariel', 'Susana', 'Tomás', 'Graciela', 'Juan', 'Bárbara', 'Maximiliano', 'Carla',
                ],
                'lastNames' => [
                    // Spanish-origin
                    'Gómez', 'Rodríguez', 'Fernández', 'García', 'Pérez', 'Martínez', 'López',
                    'González', 'Sánchez', 'Romero', 'Torres', 'Flores', 'Álvarez', 'Díaz',
                    'Medina', 'Herrera', 'Suárez', 'Morales', 'Castro', 'Ruiz', 'Acosta',
                    'Vega', 'Ríos', 'Blanco', 'Cabral', 'Ferreyra', 'Quiroga', 'Villanueva',
                    'Sosa', 'Ibáñez',
                    // Italian-origin
                    'Rossi', 'Ferrari', 'Bianchi', 'Conti', 'Gallo', 'Romano', 'Esposito',
                    'Greco', 'Leone', 'De Luca', 'Palermo', 'Mancini', 'Bruno', 'Ricci',
                    'Marino', 'Lombardi', 'Russo', 'Colombo', 'Santoro', 'Montagna',
                ],
            ],

            // -----------------------------------------------------------------
            // Dutch
            // -----------------------------------------------------------------
            'Dutch' => [
                'firstNames' => [
                    'Bram', 'Saskia', 'Thijs', 'Anouk', 'Lars', 'Fenna', 'Joost', 'Lieke',
                    'Martijn', 'Merel', 'Pim', 'Sanne', 'Willem', 'Floor', 'Gijs', 'Jiske',
                    'Koen', 'Lotte', 'Niels', 'Roos', 'Stijn', 'Tess', 'Bas', 'Elin', 'Jelle',
                    'Katja', 'Luuk', 'Maaike', 'Pieter', 'Sofie', 'Teun', 'Vera', 'Arjan',
                    'Chantal', 'Dirk', 'Femke', 'Hans', 'Inge', 'Jan', 'Karin', 'Lodewijk',
                    'Marloes', 'Onno', 'Petra', 'Rutger', 'Suus', 'Ties', 'Ursula', 'Valentijn',
                    'Wendelmoet',
                ],
                'lastNames' => [
                    'van Dijk', 'de Vries', 'Janssen', 'Bakker', 'Visser', 'Smit', 'de Jong',
                    'Mulder', 'van den Berg', 'de Groot', 'Postma', 'Bos', 'Vos', 'Hendriks',
                    'Dekker', 'Lucas', 'van Loon', 'Meijer', 'de Wit', 'Willems', 'van Leeuwen',
                    'van de Ven', 'van Beek', 'Hermans', 'de Graaf', 'van der Meer', 'van der Linden',
                    'van Vliet', 'de Bruijn', 'van Doorn', 'Hofman', 'Molenaar', 'Sanders', 'de Klerk',
                    'Groen', 'de Vos', 'van Rijn', 'de Koning', 'Schouten', 'de Boer', 'van Pelt',
                    'van Dam', 'Jacobs', 'van Wijk', 'Post', 'van Egmond', 'de Ridder', 'Bosch',
                    'van Gastel', 'van der Berg',
                ],
            ],

            // -----------------------------------------------------------------
            // Italian
            // -----------------------------------------------------------------
            'Italian' => [
                'firstNames' => [
                    'Marco', 'Giulia', 'Lorenzo', 'Sofia', 'Andrea', 'Chiara', 'Matteo', 'Martina',
                    'Alessandro', 'Valentina', 'Francesco', 'Elisa', 'Luca', 'Federica', 'Giovanni',
                    'Alessia', 'Roberto', 'Silvia', 'Antonio', 'Sara', 'Stefano', 'Laura', 'Paolo',
                    'Francesca', 'Mario', 'Elena', 'Giorgio', 'Claudia', 'Luigi', 'Serena', 'Carlo',
                    'Paola', 'Giuseppe', 'Simona', 'Alberto', 'Roberta', 'Riccardo', 'Monica',
                    'Davide', 'Michela', 'Nicola', 'Rossella', 'Fabio', 'Cristina', 'Massimo',
                    'Daniela', 'Emanuele', 'Patrizia', 'Claudio', 'Raffaella',
                ],
                'lastNames' => [
                    'Rossi', 'Russo', 'Ferrari', 'Esposito', 'Bianchi', 'Romano', 'Colombo',
                    'Ricci', 'Marino', 'Greco', 'Bruno', 'Gallo', 'Conti', 'De Luca', 'Costa',
                    'Giordano', 'Mancini', 'Leone', 'Barbieri', 'Martini', 'Fontana', 'Mariani',
                    'Ferretti', 'Rinaldi', 'Caruso', 'Ferrero', 'Pellegrini', 'Palermo', 'Sergi',
                    'Lombardi', 'Moretti', 'Galli', 'Giuliani', 'Villa', 'De Santis', 'Bernardi',
                    'Sala', 'Sanna', 'Cattaneo', 'Parisi', 'De Angelis', 'Bianco', 'Fiore',
                    'Fabbri', 'Valentini', 'Orlando', 'Monti', 'Vitale', 'Marchetti', 'Calabrese',
                ],
            ],

            // -----------------------------------------------------------------
            // Swedish
            // -----------------------------------------------------------------
            'Swedish' => [
                'firstNames' => [
                    'Erik', 'Anna', 'Lars', 'Maria', 'Karl', 'Emma', 'Gunnar', 'Karin', 'Johan',
                    'Ingrid', 'Per', 'Birgitta', 'Anders', 'Christina', 'Björn', 'Helena', 'Jan',
                    'Eva', 'Magnus', 'Kristina', 'Sven', 'Margareta', 'Peter', 'Elisabeth', 'Mikael',
                    'Cecilia', 'Thomas', 'Marianne', 'Daniel', 'Monica', 'Fredrik', 'Elin', 'Jonas',
                    'Sofia', 'Andreas', 'Sara', 'Martin', 'Amanda', 'Mattias', 'Jenny', 'Henrik',
                    'Lisa', 'David', 'Hanna', 'Niklas', 'Johanna', 'Emil', 'Linda', 'Oscar', 'Rebecka',
                ],
                'lastNames' => [
                    'Johansson', 'Andersson', 'Karlsson', 'Nilsson', 'Eriksson', 'Larsson', 'Olsson',
                    'Persson', 'Svensson', 'Gustafsson', 'Pettersson', 'Jonsson', 'Jansson', 'Hansson',
                    'Bengtsson', 'Jönsson', 'Lindberg', 'Jacobsson', 'Magnusson', 'Lindström',
                    'Lindqvist', 'Lindgren', 'Berg', 'Axelsson', 'Bergström', 'Lundgren', 'Lund',
                    'Björk', 'Mattsson', 'Eklund', 'Lundin', 'Hedlund', 'Norberg', 'Strand',
                    'Lundqvist', 'Nyström', 'Holmberg', 'Sandström', 'Bergqvist', 'Holm',
                    'Henriksson', 'Sjöberg', 'Danielsson', 'Isaksson', 'Sundström', 'Claesson',
                    'Wallin', 'Engström', 'Löfgren', 'Björklund',
                ],
            ],

            // -----------------------------------------------------------------
            // Danish
            // -----------------------------------------------------------------
            'Danish' => [
                'firstNames' => [
                    'Lars', 'Anne', 'Søren', 'Mette', 'Jens', 'Hanne', 'Thomas', 'Lise', 'Niels',
                    'Kirsten', 'Henrik', 'Pia', 'Michael', 'Lone', 'Peter', 'Trine', 'Jørgen',
                    'Gitte', 'Jan', 'Susanne', 'Per', 'Ole', 'Camilla', 'Hans', 'Charlotte',
                    'Morten', 'Louise', 'Rasmus', 'Sofie', 'Mikkel', 'Emma', 'Magnus', 'Maria',
                    'Bjarne', 'Katrine', 'Carsten', 'Julie', 'Kristian', 'Sara', 'Frederik',
                    'Anna', 'Anders', 'Nadia', 'Christian', 'Cecilie', 'Simon', 'Line', 'Emil', 'Maja',
                    'Bitten',
                ],
                'lastNames' => [
                    'Jensen', 'Nielsen', 'Hansen', 'Pedersen', 'Andersen', 'Christensen', 'Larsen',
                    'Sørensen', 'Rasmussen', 'Petersen', 'Jørgensen', 'Madsen', 'Kristensen',
                    'Olsen', 'Thomsen', 'Christiansen', 'Poulsen', 'Johansen', 'Knudsen', 'Mortensen',
                    'Møller', 'Jakobsen', 'Henriksen', 'Lund', 'Holm', 'Gregersen', 'Eriksen',
                    'Friis', 'Mikkelsen', 'Kjeldsen', 'Laursen', 'Dahl', 'Lindberg', 'Nygaard',
                    'Vestergaard', 'Berntsen', 'Clausen', 'Christoffersen', 'Riis', 'Kvist',
                    'Bech', 'Skov', 'Nissen', 'Frederiksen', 'Bendtsen', 'Jacobsen', 'Haas',
                    'Bang', 'Brøndum', 'Damgaard',
                ],
            ],

            // -----------------------------------------------------------------
            // Irish
            // -----------------------------------------------------------------
            'Irish' => [
                'firstNames' => [
                    'Ciarán', 'Aoife', 'Oisín', 'Siobhán', 'Niamh', 'Tadhg', 'Caoimhe', 'Séamus',
                    'Saoirse', 'Pádraig', 'Clíona', 'Cormac', 'Gráinne', 'Eoin', 'Órlaith', 'Rónán',
                    'Éabha', 'Fionn', 'Muirne', 'Cathal', 'Deirdre', 'Diarmuid', 'Bríd', 'Fearghus',
                    'Méabh', 'Cillian', 'Sorcha', 'Colm', 'Ailbhe', 'Donncha', 'Ruairí', 'Aisling',
                    'Conor', 'Sinéad', 'Liam', 'Ciara', 'Seán', 'Mairéad', 'Declan', 'Róisín',
                    'Brendan', 'Una', 'Donal', 'Máire', 'Fergus', 'Nóra', 'Patrick', 'Brigid',
                    'Kevin', 'Páraic',
                ],
                'lastNames' => [
                    "O'Brien", 'Murphy', 'Kelly', 'Walsh', "O'Sullivan", 'Smith', "O'Connor",
                    'McCarthy', 'Byrne', 'Ryan', "O'Neill", 'Doyle', 'Reilly', 'Sheehan',
                    'Quinn', 'Kavanagh', 'Lynch', 'Murray', "O'Callaghan", 'McGuinness',
                    'Dunne', 'Moore', 'Gallagher', "O'Rourke", 'Kennedy', "O'Mahony", 'Nolan',
                    'Doherty', 'McGrath', 'Brennan', 'Flanagan', 'Madden', 'Connolly', "O'Shea",
                    'Buckley', 'Barry', 'Keane', 'Coleman', 'Boyle', 'Burke', 'Collins', 'Cronin',
                    'Daly', 'Farrell', 'Fitzgerald', 'Fleming', 'Hogan', 'Keenan', 'Malone',
                    'MacCarthy',
                ],
            ],

            // -----------------------------------------------------------------
            // Chinese (romanised)
            // -----------------------------------------------------------------
            'Chinese' => [
                'firstNames' => [
                    'Wei', 'Xiaotong', 'Zihan', 'Meiling', 'Jun', 'Ruoxi', 'Haoyu', 'Yinuo',
                    'Zihao', 'Xinyi', 'Yuze', 'Mengjie', 'Yuchen', 'Jiayi', 'Haoran', 'Shihan',
                    'Zixuan', 'Kexin', 'Yuxuan', 'Yuyan', 'Zichen', 'Wenjing', 'Jiahao', 'Xiaodan',
                    'Mingze', 'Ruolan', 'Zitao', 'Lihua', 'Tianyu', 'Yunxi', 'Peng', 'Fang',
                    'Bo', 'Cai', 'De', 'Enlai', 'Feng', 'Guang', 'Heng', 'Jian', 'Kun', 'Li',
                    'Min', 'Ning', 'Qiang', 'Rong', 'Sheng', 'Tao', 'Xian', 'Yang',
                ],
                'lastNames' => [
                    'Zhang', 'Chen', 'Huang', 'Zhao', 'Li', 'Wu', 'Liu', 'Yang', 'Zhou', 'Xu',
                    'Sun', 'Ma', 'Zhu', 'Hu', 'Guo', 'He', 'Gao', 'Lin', 'Luo', 'Liang', 'Song',
                    'Zheng', 'Xie', 'Han', 'Tang', 'Feng', 'Yu', 'Dong', 'Xiao', 'Cheng', 'Cao',
                    'Yuan', 'Deng', 'Fu', 'Shen', 'Zeng', 'Peng', 'Su', 'Lu', 'Jiang', 'Ye',
                    'Yan', 'Pan', 'Wei', 'Fan', 'Jin', 'Jia', 'Yao', 'Bai', 'Shao',
                ],
            ],

        ];
    }
}
